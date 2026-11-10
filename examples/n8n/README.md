# FraudNova n8n workflow templates

Importable [n8n](https://n8n.io) workflows that add FraudNova fraud checks to an automation you probably already run. The three templates in the table use only n8n's built-in nodes (HTTP Request plus one trigger and one destination each), so there is nothing to install, and they run on n8n Cloud or self-hosted. The [lead gate v2](#lead-gate-v2-community-node-google-sheets-telegram) below is the production version of the first template, built on the FraudNova community node.

Prefer a single node over HTTP Request nodes? Install the [FraudNova community node](https://www.npmjs.com/package/n8n-nodes-fraudnova) (`n8n-nodes-fraudnova`): one credential, IP/email/phone checks, batch support and a `flagged` field for your IF node. The three templates stay the no-install option.

| File | Trigger | What it checks | Result |
| --- | --- | --- | --- |
| [`lead-gate-webhook.json`](lead-gate-webhook.json) | Webhook (`POST /webhook/fraudnova-lead-gate`) with `email` and `ip` in the JSON body | Email (`/check/email`) and IP (`/check`) | Responds `{"accepted": false, "reason": "...", "scores": {...}}` when the email is disposable, either score is at or above the threshold, or the IP is a proxy, VPN or Tor exit; otherwise `{"accepted": true, "scores": {...}}`. Works with your own site or any tool that lets you set the JSON body and read the reply (a custom form handler, another n8n workflow, a Make or Zapier webhook step). Form builders that post a fixed payload without the visitor's IP and ignore the response (for example Tally or Typeform) cannot drive it as-is. If `ip` is missing the IP check errors and is treated as not checked, so the decision rests on the email alone. |
| [`lead-gate-webhook-v2.json`](lead-gate-webhook-v2.json) | Webhook (`POST /webhook/lead-gate`) with `email`, `phone`, `country`, `ip`, optional `name` and `source` | Email, phone and IP through the FraudNova community node, after a Google Sheets duplicate lookup | Sorts the lead into **accept** (200), **review** (200, Telegram alert) or **reject** (403), appends a row to the matching Sheets tab and always replies. See [Lead gate v2](#lead-gate-v2-community-node-google-sheets-telegram). |
| [`woocommerce-order-risk-telegram.json`](woocommerce-order-risk-telegram.json) | WooCommerce Trigger, `order.created` | Billing phone and country (`/check/phone`), billing email (`/check/email`), customer IP (`/check`) | Sends a Telegram message with the order number, total, customer name, the three scores and the flags when any score is at or above the threshold, the phone is VOIP or risky, the email is disposable, or the IP is a proxy, VPN or Tor exit. Other orders end the run. |
| [`sheet-phone-enrichment.json`](sheet-phone-enrichment.json) | Google Sheets Trigger, row added | Phone from the `phone` column and an optional `country` column (`/check/phone`) | Writes `valid`, `line_type`, `carrier`, `fraud_score`, `risky`, `checked_at` and `status` back to the row, matched on `phone` (a duplicate phone number updates the first matching row). A failed check writes `status` = `error`. |

## Lead gate v2 (community node, Google Sheets, Telegram)

[`lead-gate-webhook-v2.json`](lead-gate-webhook-v2.json) is the production version of the lead gate: 21 working nodes instead of 7, built on the [FraudNova community node](https://www.npmjs.com/package/n8n-nodes-fraudnova) rather than HTTP Request nodes. The v1 file stays in place for existing links.

### What changed from v1

| | v1 | v2 |
| --- | --- | --- |
| Checks | Email and IP, two HTTP Request nodes | Email, phone (with `country`) and IP, three FraudNova nodes |
| Missing `ip` | IP check errors, treated as not checked | Falls back to `CF-Connecting-IP`, the first `X-Forwarded-For` address, then `X-Real-IP` |
| Duplicates | None | Email looked up in the **Leads** tab; a lead seen within `dedupe_hours` (24) is answered `{"duplicate": true, "accepted": true}` and costs no credits |
| Decision | Accept or reject against one threshold | **Decide** (Code node) takes the highest of the three scores and collects reasons; `review_at` (60) and `reject_at` (85) plus `reject_flags` (`disposable,honeypot,tor`) sort the lead into accept, review or reject |
| Failed check | Accepted with null scores | Held for **review** with reason `check_failed`: fail open to a human, never a silent accept. If every check failed, the Telegram alert reads "FraudNova checks unavailable" |
| Storage | None | A row appended to the **Leads**, **Review** or **Rejected** tab of one Google Sheet |
| Alerts | None | Telegram message for every review case, plus an **Error Trigger** branch that alerts on anything unexpected |
| Reply | 200 for both outcomes | 200 `{"accepted": true, "score", "scores"}`, 200 `{"accepted": true, "review": true, "score", "reasons"}`, or 403 `{"accepted": false, "reasons", "score"}` |

Decision rules, in order: any flag (proxy, VPN, Tor, bot, disposable, honeypot, VOIP, risky phone, recent abuse, invalid email or phone) or a highest score at or above `review_at` means review; a failed check means review (reason `check_failed`); a lead with no email, phone or IP means review (`nothing_to_check`); a reason listed in `reject_flags`, or a highest score at or above `reject_at`, means reject. A reject decision wins over a failed check, because the lead is already known to be bad. The Decide node is wrapped in `try`/`catch`, so a bug there still ends in review (`decide_error`). Fields that are empty in the request are skipped, not treated as failures, so a form without a phone field works and costs 2 credits instead of 3.

### Setup

1. Install the FraudNova community node (`n8n-nodes-fraudnova`, under **Settings, Community nodes** on self-hosted; on n8n Cloud it is a verified node and shows up in the node panel) and create its credential (**FraudNova API**, named `FraudNova account` to match the import) with a key from [fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys).
2. Create a Google Sheet with three tabs named `Leads`, `Review` and `Rejected`, each with this header row in row 1:

   ```
   created_at, decision, email, phone, country, ip, name, source, max_score, ip_score, email_score, phone_score, reasons, checks_failed
   ```

   The duplicate lookup reads `email` and `created_at` from the **Leads** tab, so those two columns must exist there. Connect a Google Sheets OAuth2 credential (named `Google Sheets account`) and select it in the four Google Sheets nodes.
3. Create a Telegram bot, connect it as a Telegram credential (`Telegram account`) and select it in both Telegram nodes.
4. Open **Settings** and replace `YOUR_GOOGLE_SHEET_ID` (the long id in the sheet's URL) and `YOUR_TELEGRAM_CHAT_ID`. Also set the chat id on **Alert failure** directly: it runs in an error execution where the Settings node is not available.
5. Activate the workflow and POST a test lead:

   ```bash
   curl -X POST "https://YOUR-N8N/webhook/lead-gate" -H "Content-Type: application/json" \
     -d '{"email": "test@mailinator.com", "phone": "+1 817 679 1808", "country": "US", "ip": "185.220.101.1", "name": "Test", "source": "landing-a"}'
   ```

   Send it twice: the second call returns `duplicate: true` without spending credits.

### Cost

3 credits per new lead (2 when the request has no phone), 0 for duplicates. The FraudNova node times out after 30 seconds per check; a timed-out check counts as failed and sends the lead to review.

### Customization

Change the thresholds, `reject_flags` and `dedupe_hours` in **Settings** (remove `tor` from `reject_flags` if Tor users are welcome; add `vpn` to reject VPNs outright). Replace the two Telegram nodes with Slack, Discord or email. Add your CRM node after **Append to Leads**, or after **Route decision**'s `accept` output if you do not want the sheet.

### Import test

The committed file was validated structurally (`python3 validate.py`, see below) and the Decide node's JavaScript was unit-tested under Node against 16 scenarios (clean, each flag, each threshold, one or all checks failed, a skipped phone, shuffled merge order, a thrown exception). It was **not** imported into a running n8n before it was committed: no n8n instance was available on the machine that produced it. A live import test on n8n Cloud, with the FraudNova node installed and the curl above run twice, is still to be done before the file is submitted anywhere. `python3 validate.py` checks the structure: every node reachable from a trigger, every connection target exists, the three FraudNova nodes continue on error, the four Respond to Webhook nodes each sit on exactly one branch, and the Error Trigger branch stays off the request path.

## Import a template

1. In n8n open **Workflows**, then **Create workflow**, then the **...** menu, then **Import from File** (or paste the JSON onto the canvas).
2. Create the credential below and select it in every node named *Check ...*.
3. Open the **Settings** node and set the value (see below).
4. Connect the trigger and destination credentials (Telegram, WooCommerce or Google Sheets), then activate the workflow.

Each workflow's canvas carries a yellow overview note with three sections (How it works, Setup, Customization), white section notes that label groups of nodes, and, where a node needs your input, a red warning note.

Paste the key alone into the Bearer Auth credential; n8n adds the `Bearer ` prefix. (With a Header Auth credential you would have to type the prefix yourself, and a bare `fn_...` value fails with a 401.)

**Sheet template:** the sheet needs the header columns `phone`, optional `country`, `valid`, `line_type`, `carrier`, `fraud_score`, `risky`, `checked_at` and `status`. Format the `phone` column as plain text (or include `+` and the country code) so leading zeros are not lost. Pick your document in both Google Sheets nodes; **Update row** is preset to the tab named `Sheet1` (by name) so its column mapping survives the import, so change that if your tab has another name.

## The credential

Create one **Bearer Auth** credential in n8n named exactly `FraudNova API` (HTTP Request node, Authentication *Generic Credential Type*, type *Bearer Auth*):

- **Bearer Token:** your key, for example `fn_your_api_key_here`

n8n adds the `Bearer ` prefix itself, so paste the key alone.

Get a key under **Dashboard, API keys** at [fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys). Keys start with `fn_` and are shown once.

## The threshold

The lead gate and the WooCommerce workflow have a **Settings** node with `risk_threshold`, default **75** (the top of the "suspicious" band). Lower it for strict offers, raise it (for example 85) to act only on the worst traffic. The [fraud score thresholds guide](https://fraudnova.com/guides/fraud-score-thresholds-explained) explains how to choose. The sheet workflow has no decision to make; its Settings node holds `default_country`, used for rows that have no `country` value.

## What a failed check does

Every HTTP Request node has *Continue on fail* on and every decision first checks `success === true`. If a check fails (no credits left (402), rate limit (429), bad input (422) or an upstream error (503)), it is treated as "not checked", never as risky: the lead gate accepts the lead with `scores: { "email": null, "ip": null }`, the WooCommerce workflow does not alert on what it could not check, and the sheet row is marked `error`.

## Cost

One credit per check, whichever type. The lead gate uses 2 credits per lead, the WooCommerce workflow 3 per order, the sheet workflow 1 per row. Each workflow checks once per item and never retries. HTTP timeouts are 15 seconds in the lead gate, because a form caller cannot wait a minute, and 60 seconds in the WooCommerce and sheet workflows, because the server can take up to about a minute to fail over between keys and bills the check even if the client gives up. A timed-out check is treated as not checked. Rate limits and the per-account concurrency cap are in the [docs](https://fraudnova.com/docs).

## Send the alert somewhere else

Replace the last node. In the WooCommerce workflow delete **Telegram alert** and connect the `true` output of **Risky?** to a Slack, Discord, Email or Microsoft Teams node; the same expressions (`$('Check phone').item.json.data.fraud_score` and friends) are available in its message field. In the lead gate, add your CRM or Airtable node after **Respond accepted** to store accepted leads. In the sheet workflow, swap Google Sheets for Airtable or a database node.

## Descriptions (fallback, the Creator Portal normally generates these)

n8n's Creator Portal generates the listing title and description automatically from the workflow. These paste-ready descriptions, one per template, are a fallback.

### Lead gate (webhook)

```markdown
# Reject fake leads with FraudNova: email and IP fraud check for any webhook

## What it does
Receives a JSON POST with an email address and a visitor IP, checks both with the FraudNova API, and replies with an accept or reject decision. A lead is rejected when the email is disposable, either fraud score reaches your threshold, or the IP is a proxy, VPN or Tor exit. Both outcomes get a reply, so the caller never hangs. A failed check never rejects a lead: it is accepted with null scores.

## Setup
1. Create a Bearer Auth credential named `FraudNova API` and paste your key as the token.
2. Set `risk_threshold` in the Settings node (default 75).
3. POST `{ "email", "ip" }` to the webhook URL from your site or another workflow.
4. Each lead uses 2 credits.

## Who it is for
Marketers, affiliates and developers who collect leads through their own form handler and want to filter disposable emails and proxy traffic before the lead reaches a CRM or a network.
```

### Lead gate v2 (community node, Sheets, Telegram)

```markdown
# Score, dedupe and route every lead with FraudNova: accept, review or reject

## What it does
Receives a lead by webhook (email, phone, country, IP, optional name and source), looks the email up in a Google Sheet to skip duplicates, then runs the FraudNova email, phone and IP checks. A Code node keeps the highest score and collects the reasons (proxy, VPN, Tor, disposable, honeypot, VOIP, recent abuse) and sorts the lead into accept, review or reject. Each outcome appends a row to its own Sheets tab, review cases are sent to Telegram, and the caller always gets a reply (200, or 403 for a reject). A failed check holds the lead for review instead of accepting it silently, and an Error Trigger branch alerts on anything unexpected.

## Setup
1. Install the FraudNova community node and create its credential with your API key.
2. Create a Google Sheet with the tabs Leads, Review and Rejected (same header row, listed on the canvas) and connect Google Sheets and Telegram.
3. Paste the sheet id and chat id into the Settings node and set the thresholds (review at 60, reject at 85).
4. POST a test lead to the webhook. Each new lead uses 3 credits; duplicates use none.

## Who it is for
Affiliates, lead-gen teams and developers who want every lead scored, logged and routed before it reaches a CRM or a network, with a human in the loop for the grey zone.
```

### WooCommerce order risk alerts on Telegram

```markdown
# Get a Telegram alert for risky WooCommerce orders with FraudNova

## What it does
Runs when WooCommerce creates an order and checks the billing phone, the billing email and the customer IP with the FraudNova API. If any score reaches your threshold, the phone is VOIP or risky, the email is disposable, or the IP is a proxy, VPN or Tor exit, it sends a Telegram message with the order number, total, customer name, the three scores and the flags. Other orders end quietly, and a failed check never triggers an alert.

## Setup
1. Create a Bearer Auth credential named `FraudNova API` and paste your key as the token.
2. Set `risk_threshold` in the Settings node (default 75).
3. Connect WooCommerce and a Telegram bot, and set the chat id.
4. Each order uses 3 credits.

## Who it is for
Store owners who want to spot fraudulent or high-risk orders before shipping, without reviewing every order by hand.
```

### Google Sheets phone number enrichment

```markdown
# Enrich a Google Sheet of phone numbers with FraudNova line type and risk

## What it does
Watches a Google Sheet for new rows, checks the phone number in each row with the FraudNova API, and writes the result back to the same row: validity, line type, carrier, fraud score, a risky flag, a timestamp and a status. If a check fails, the row gets the status `error` and nothing else is changed.

## Setup
1. Create a Bearer Auth credential named `FraudNova API` and paste your key as the token.
2. Add the header columns `phone`, `country` (optional), `valid`, `line_type`, `carrier`, `fraud_score`, `risky`, `checked_at` and `status`, and format `phone` as plain text.
3. Connect Google Sheets and pick your document in both Google Sheets nodes; Update row is preset to the tab named `Sheet1`, so change that if yours differs.
4. Each row uses 1 credit. Rows are matched on `phone`.

## Who it is for
Anyone cleaning a lead or contact list who wants to know which numbers are real, mobile or VOIP before running an SMS or call campaign.
```

## Check the files

`python3 validate.py` verifies the structure of every template (valid JSON, required node keys, every working node reachable from a trigger, every connection target exists, credentials by name only, both webhook responders connected, failed checks never read as risky) and the sticky-note rules (one yellow overview with the three headings and 100 to 300 words, white section notes, short red warnings). For `lead-gate-webhook-v2.json` it also checks the three FraudNova nodes and every Sheets and Telegram node continue on error, the Merge node is fed by all three checks, each of the four Respond to Webhook nodes sits on exactly one branch, the Error Trigger branch never reaches a responder, only placeholders are present, and the overview ends with the `@[youtube](...)` tutorial embed (it prints a reminder while the id is still `VIDEO_ID`).

## Publishing to n8n's template library

For the maintainer. The credential is referenced by name only, so the files carry no ids or secrets.

Official guides:

- [n8n Creator hub](https://n8n.notion.site/n8n-Creator-hub-7bd2cbe0fce0449198ecb23ff4a2f76f)
- [Add sticky notes to templates](https://n8n.notion.site/Add-sticky-notes-to-templates-2aa5b6e0c94f8058b0aefddd02655887)

To submit a template:

1. Export the workflow JSON (these files).
2. Import it into a fresh workflow in n8n.
3. Follow your own instructions on the canvas (credential, settings, trigger and destination) to confirm they work.
4. Submit it through the Creator Dashboard (<https://creators.n8n.io>).

To replace a submission the reviewers sent back (the v1 lead gate was declined on 2026-11-09 as too basic; v2 is the answer): in the Creator Dashboard open the pending template, choose **Implement changes**, upload `lead-gate-webhook-v2.json`, then **Submit for human review**. Before that upload, replace `VIDEO_ID` in the overview sticky (`@[youtube](VIDEO_ID)`, last line) with the id of the walkthrough video, and import-test the exact file on n8n Cloud.

## Using the FraudNova node instead of HTTP Request

If you installed the [FraudNova community node](https://www.npmjs.com/package/n8n-nodes-fraudnova) (`n8n-nodes-fraudnova`), import [`node/fraudnova-node-demo.json`](node/fraudnova-node-demo.json) to see every operation in one workflow: a batch email check, IP checks feeding an IF node on the `flagged` field, the account balance, and an AI Agent that calls FraudNova as a tool. In n8n choose **Import from URL** and paste:

```
https://raw.githubusercontent.com/dahleedam/fraudnova-examples/main/examples/n8n/node/fraudnova-node-demo.json
```

It needs a FraudNova API credential and, for the agent branch only, an Anthropic credential; delete the agent branch if you don't use one. The three templates above stay the no-install option.
