#!/usr/bin/env python3
"""Sanity-check the n8n workflow templates in this folder.

Run: python3 validate.py
Exit 0 when every template passes, 1 otherwise. No third-party packages needed.
"""
import glob
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
# Split so this file does not itself contain the upstream provider's name.
FORBIDDEN = ("IPQuality" + "Score", "IP" + "QS")
REQUIRED_NODE_KEYS = ("name", "type", "typeVersion", "position", "parameters")
errors = []


def err(file, msg):
    errors.append(f"{os.path.basename(file)}: {msg}")


def reachable(connections, start, targets):
    """Names of nodes reachable from `start` following any output."""
    seen, stack = set(), [start]
    while stack:
        cur = stack.pop()
        for output in connections.get(cur, {}).get("main", []):
            for edge in output:
                if edge["node"] not in seen:
                    seen.add(edge["node"])
                    stack.append(edge["node"])
    return seen


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
            if creds.get("httpHeaderAuth", {}).get("name") != "FraudNova API":
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
        for output in outs.get("main", []):
            for edge in output:
                if edge["node"] not in names:
                    err(path, f"connection {src!r} -> {edge['node']!r}: target is not a node")

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

    # Every IF expression must check success === true before reading data.*
    for n in nodes:
        if n["type"] == "n8n-nodes-base.if":
            for c in n["parameters"]["conditions"]["conditions"]:
                left = c["leftValue"]
                if ".data." in left and ".success === true" not in left:
                    err(path, f"IF condition reads data without checking success: {left[:80]}")

    print(("OK   " if not any(e.startswith(os.path.basename(path) + ":") for e in errors) else "FAIL ")
          + os.path.basename(path) + f" ({len(nodes)} nodes)")

for e in errors:
    print("  - " + e)
sys.exit(1 if errors else 0)
