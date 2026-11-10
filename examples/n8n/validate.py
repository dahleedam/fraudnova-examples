#!/usr/bin/env python3
"""Sanity-check the n8n workflow templates in this folder.

Run: python3 validate.py
Exit 0 when every template passes, 1 otherwise. No third-party packages needed.
"""
import glob
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
# Split so this file does not itself contain the upstream provider's name.
FORBIDDEN = ("IPQuality" + "Score", "IP" + "QS")
REQUIRED_NODE_KEYS = ("name", "type", "typeVersion", "position", "parameters")
errors = []
notes = []


def stickies_of(nodes):
    return [n for n in nodes if n["type"] == "n8n-nodes-base.stickyNote"]


def err(file, msg):
    errors.append(f"{os.path.basename(file)}: {msg}")


def reachable(connections, start, targets=None, output_index=None):
    """Names of nodes reachable from `start` following every output (or one output index)."""
    seen, stack = set(), []
    for conn_type, outputs in connections.get(start, {}).items():
        for i, output in enumerate(outputs):
            if output_index is not None and i != output_index:
                continue
            for edge in output:
                stack.append(edge["node"])
    while stack:
        cur = stack.pop()
        if cur in seen:
            continue
        seen.add(cur)
        for conn_type, outputs in connections.get(cur, {}).items():
            for output in outputs:
                for edge in output:
                    stack.append(edge["node"])
    return seen


TRIGGER_TYPES = ("n8n-nodes-base.webhook", "n8n-nodes-base.manualTrigger", "n8n-nodes-base.errorTrigger")


def is_trigger(node):
    return node["type"] in TRIGGER_TYPES or node["type"].endswith("Trigger")


files = sorted(glob.glob(os.path.join(HERE, "*.json")))
if not files:
    errors.append("no workflow files found")

for path in files:
    raw = open(path, encoding="utf-8").read()
    for bad in FORBIDDEN:
        if bad.lower() in raw.lower():
            err(path, f"contains forbidden string {bad!r}")
    try:
        wf = json.loads(raw)
    except ValueError as e:
        err(path, f"invalid JSON: {e}")
        continue

    for key in ("name", "nodes", "connections", "settings", "pinData"):
        if key not in wf:
            err(path, f"missing top-level key {key!r}")
    nodes = wf.get("nodes", [])
    names = [n.get("name") for n in nodes]
    if len(names) != len(set(names)):
        err(path, "duplicate node names")

    for n in nodes:
        for key in REQUIRED_NODE_KEYS:
            if key not in n:
                err(path, f"node {n.get('name')!r} missing {key!r}")
        if len(n.get("position", [])) != 2:
            err(path, f"node {n.get('name')!r} position must be [x, y]")
        creds = n.get("credentials", {})
        for c in creds.values():
            if "id" in c:
                err(path, f"node {n['name']!r} credential must be referenced by name only (no id)")
        if n.get("type") == "n8n-nodes-base.httpRequest":
            if creds.get("httpBearerAuth", {}).get("name") != "FraudNova API":
                err(path, f"HTTP node {n['name']!r} must use credential 'FraudNova API'")
            if n.get("onError") != "continueRegularOutput":
                err(path, f"HTTP node {n['name']!r} must set onError=continueRegularOutput")
            p = n["parameters"]
            if p.get("method") != "GET" or not p.get("url", "").startswith("https://fraudnova.com/api/v1/"):
                err(path, f"HTTP node {n['name']!r} must GET https://fraudnova.com/api/v1/...")

    conns = wf.get("connections", {})
    for src, outs in conns.items():
        if src not in names:
            err(path, f"connection source {src!r} is not a node")
        for conn_type, outputs in outs.items():
            for output in outputs:
                for edge in output:
                    if edge["node"] not in names:
                        err(path, f"connection {src!r} -> {edge['node']!r}: target is not a node")

    # Every working node must be a trigger or sit downstream of one.
    working = [n for n in nodes if n["type"] != "n8n-nodes-base.stickyNote"]
    triggers = [n["name"] for n in working if is_trigger(n)]
    if not triggers:
        err(path, "no trigger node")
    downstream = set()
    for t in triggers:
        downstream |= reachable(conns, t)
    for n in working:
        if n["name"] not in triggers and n["name"] not in downstream:
            err(path, f"node {n['name']!r} is not reachable from any trigger")

    if os.path.basename(path) == "lead-gate-webhook.json":
        responders = [n["name"] for n in nodes if n["type"] == "n8n-nodes-base.respondToWebhook"]
        outs = conns.get("Risky?", {}).get("main", [])
        if len(responders) != 2:
            err(path, "webhook template needs exactly two Respond to Webhook nodes")
        if len(outs) != 2 or not all(outs):
            err(path, "'Risky?' must have both a true and a false output connected")
        else:
            for r in responders:
                if r not in reachable(conns, "Risky?", responders):
                    err(path, f"{r!r} is not reachable from 'Risky?'")
            if {e["node"] for e in outs[0]} == {e["node"] for e in outs[1]}:
                err(path, "'Risky?' true and false branches must go to different responders")

    if os.path.basename(path) == "lead-gate-webhook-v2.json":
        by_name = {n["name"]: n for n in nodes}

        def need(name, type_):
            n = by_name.get(name)
            if n is None or n["type"] != type_:
                err(path, f"v2 needs a node {name!r} of type {type_}")
            return n

        webhook = need("Webhook", "n8n-nodes-base.webhook")
        if webhook and webhook["parameters"].get("responseMode") != "responseNode":
            err(path, "v2 Webhook must use responseMode=responseNode")

        # The three checks use the community node, fail open, and read the normalized lead.
        checks = [n for n in nodes if n["type"] == "n8n-nodes-fraudnova.fraudNova"]
        if len(checks) != 3 or {n["parameters"].get("resource") for n in checks} != {"ip", "email", "phone"}:
            err(path, "v2 needs exactly three FraudNova nodes: ip, email and phone")
        for n in checks:
            p = n["parameters"]
            if n.get("onError") != "continueRegularOutput":
                err(path, f"FraudNova node {n['name']!r} must set onError=continueRegularOutput")
            if n.get("credentials", {}).get("fraudNovaApi", {}).get("name") != "FraudNova account":
                err(path, f"FraudNova node {n['name']!r} must use credential 'FraudNova account'")
            if p.get("operation") != "check" or not str(p.get(p.get("resource"), "")).startswith("={{"):
                err(path, f"FraudNova node {n['name']!r} must run 'check' on an expression")
            if p.get("resource") == "phone" and "country" not in p.get("options", {}):
                err(path, "the phone check must pass options.country")

        # External nodes on the request path must not stop the run (the form must always get a reply).
        for n in nodes:
            if n["type"] in ("n8n-nodes-base.googleSheets", "n8n-nodes-base.telegram") and n.get("onError") != "continueRegularOutput":
                err(path, f"{n['name']!r} must set onError=continueRegularOutput")
        finder = need("Find existing lead", "n8n-nodes-base.googleSheets")
        if finder and not finder.get("alwaysOutputData"):
            err(path, "'Find existing lead' needs alwaysOutputData so a new lead still reaches the checks")

        merge = need("Merge checks", "n8n-nodes-base.merge")
        if merge:
            if merge["parameters"].get("numberInputs") != 3:
                err(path, "'Merge checks' must have numberInputs=3")
            feeders = {}
            for src, outs in conns.items():
                for output in outs.get("main", []):
                    for e in output:
                        if e["node"] == "Merge checks":
                            feeders[e["index"]] = src
            if sorted(feeders) != [0, 1, 2] or set(feeders.values()) != {n["name"] for n in checks}:
                err(path, f"'Merge checks' must be fed on inputs 0, 1, 2 by the three checks, got {feeders}")

        need("Decide", "n8n-nodes-base.code")
        switch = need("Route decision", "n8n-nodes-base.switch")
        gate = need("Seen recently?", "n8n-nodes-base.if")

        # Four responders: one per decision branch plus the duplicate short-circuit, each on its own branch.
        responders = {n["name"] for n in nodes if n["type"] == "n8n-nodes-base.respondToWebhook"}
        if len(responders) != 4:
            err(path, f"v2 needs exactly four Respond to Webhook nodes, found {len(responders)}")
        roots = []
        if gate:
            gate_outs = conns.get("Seen recently?", {}).get("main", [])
            if len(gate_outs) != 2 or not all(gate_outs):
                err(path, "'Seen recently?' must have both outputs connected")
            roots.append(("Seen recently?", 0))
        if switch:
            sw_outs = conns.get("Route decision", {}).get("main", [])
            if len(sw_outs) != 3 or not all(sw_outs):
                err(path, "'Route decision' must have three connected outputs (accept, review, reject)")
            roots += [("Route decision", i) for i in range(3)]
        seen_by = {r: [] for r in responders}
        for root, idx in roots:
            hit = reachable(conns, root, output_index=idx) & responders
            if len(hit) != 1:
                err(path, f"branch {root!r}[{idx}] must reach exactly one responder, reaches {sorted(hit)}")
            for r in hit:
                seen_by[r].append((root, idx))
        for r, where in seen_by.items():
            if len(where) != 1:
                err(path, f"responder {r!r} must sit on exactly one branch, found {where}")
            if reachable(conns, r) & responders:
                err(path, f"responder {r!r} must not lead to another responder")
        if gate and "Respond duplicate" not in (reachable(conns, "Seen recently?", output_index=0) & responders):
            err(path, "the true output of 'Seen recently?' must end in 'Respond duplicate'")

        # Error branch: its own trigger, one alert, never wired into the request path.
        et = need("Error Trigger", "n8n-nodes-base.errorTrigger")
        if et:
            tail = reachable(conns, "Error Trigger")
            if not any(by_name[t]["type"] == "n8n-nodes-base.telegram" for t in tail if t in by_name):
                err(path, "'Error Trigger' must lead to a Telegram alert")
            if tail & responders:
                err(path, "the error branch must not reach a Respond to Webhook node")
            if "Error Trigger" in reachable(conns, "Webhook"):
                err(path, "'Error Trigger' must not be downstream of the Webhook")

        # Placeholders only: no real-looking key, sheet id or chat id.
        if re.search(r"fn_[A-Za-z0-9]{12,}", raw):
            err(path, "contains something that looks like a real API key")
        for ph in ("YOUR_GOOGLE_SHEET_ID", "YOUR_TELEGRAM_CHAT_ID"):
            if ph not in raw:
                err(path, f"placeholder {ph} missing")

        ov = [n for n in stickies_of(nodes) if n["parameters"].get("color", 1) == 1]
        if ov and "@[youtube](" not in ov[0]["parameters"].get("content", ""):
            err(path, "overview sticky must end with the @[youtube](VIDEO_ID) tutorial embed")
        if "@[youtube](VIDEO_ID)" in raw:
            notes.append(f"{os.path.basename(path)}: the tutorial embed still carries the VIDEO_ID placeholder; swap in the real id before submitting")

    # Every IF expression must check success === true before reading data.*
    for n in nodes:
        if n["type"] == "n8n-nodes-base.if":
            for c in n["parameters"]["conditions"]["conditions"]:
                left = c["leftValue"]
                if ".data." in left and ".success === true" not in left:
                    err(path, f"IF condition reads data without checking success: {left[:80]}")

    # Sticky-note rules (n8n Creator Hub): one yellow overview, white section notes, optional red warnings.
    stickies = [n for n in nodes if n["type"] == "n8n-nodes-base.stickyNote"]
    others = [n for n in nodes if n["type"] != "n8n-nodes-base.stickyNote"]

    def words(n):
        return len(n["parameters"].get("content", "").split())

    overviews = [n for n in stickies if n["parameters"].get("color", 1) == 1]
    if len(overviews) != 1:
        err(path, f"needs exactly one overview sticky (color absent or 1), found {len(overviews)}")
    else:
        ov = overviews[0]
        content = ov["parameters"].get("content", "")
        headings = [ln.strip() for ln in content.splitlines() if ln.startswith("### ")]
        if headings != ["### How it works", "### Setup", "### Customization"]:
            err(path, f"overview must have the H3 headings How it works / Setup / Customization in order, found {headings}")
        if not 100 <= words(ov) <= 300:
            err(path, f"overview must be 100-300 words, is {words(ov)}")
    whites = [n for n in stickies if n["parameters"].get("color") == 7]
    if len(others) >= 4 and len(whites) < 2:
        err(path, "workflows with 4+ nodes need at least two white (color 7) section notes")
    for n in stickies:
        color = n["parameters"].get("color")
        limit = {7: 50, 3: 30}.get(color)
        if limit is not None and words(n) >= limit:
            err(path, f"sticky {n['name']!r} (color {color}) must be under {limit} words, is {words(n)}")
        if color not in (None, 1, 3, 7):
            err(path, f"sticky {n['name']!r} uses unsupported color {color}")

    print(("OK   " if not any(e.startswith(os.path.basename(path) + ":") for e in errors) else "FAIL ")
          + os.path.basename(path) + f" ({len(nodes)} nodes)")

for e in errors:
    print("  - " + e)
for n in notes:
    print("  note: " + n)
sys.exit(1 if errors else 0)
