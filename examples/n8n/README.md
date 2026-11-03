# FraudNova n8n workflow templates

Three importable [n8n](https://n8n.io) workflows that add FraudNova fraud checks to an automation you probably already run. They use only n8n's built-in nodes (HTTP Request plus one trigger and one destination each), so there is nothing to install, and they run on n8n Cloud or self-hosted.

Prefer a single node over HTTP Request nodes? Install the [FraudNova community node](https://www.npmjs.com/package/n8n-nodes-fraudnova) (`n8n-nodes-fraudnova`): one credential, IP/email/phone checks, batch support and a `flagged` field for your IF node. These templates stay the no-install option.

| File | Trigger | What it checks | Result |
| --- | --- | --- | --- |
| [`lead-gate-webhook.json`](lead-gate-webhook.json) | Webhook (`POST /webhook/fraudnova-lead-gate`) with `email` and `ip` in the JSON body | Email (`/check/email`) and IP (`/check`) | Responds `{"accepted": false, "reason": "...", "scores": {...}}` when the email is disposable, either score is at or above the threshold, or the IP is a proxy, VPN or Tor exit; otherwise `{"accepted": true, "scores": {...}}`. Works with your own site or any tool that lets you set the JSON body and read the reply (a custom form handler, another n8n workflow, a Make or Zapier webhook step). Form builders that post a fixed payload without the visitor's IP and ignore the response (for example Tally or Typeform) cannot drive it as-is. If `ip` is missing the IP check errors and is treated as not checked, so the decision rests on the email alone. |
| [`woocommerce-order-risk-telegram.json`](woocommerce-order-risk-telegram.json) | WooCommerce Trigger, `order.created` | Billing phone and country (`/check/phone`), billing email (`/check/email`), customer IP (`/check`) | Sends a Telegram message with the order number, total, customer name, the three scores and the flags when any score is at or above the threshold, the phone is VOIP or risky, the email is disposable, or the IP is a proxy, VPN or Tor exit. Other orders end the run. |
| [`sheet-phone-enrichment.json`](sheet-phone-enrichment.json) | Google Sheets Trigger, row added | Phone from the `phone` column and an optional `country` column (`/check/phone`) | Writes `valid`, `line_type`, `carrier`, `fraud_score`, `risky`, `checked_at` and `status` back to the row, matched on `phone` (a duplicate phone number updates the first matching row). A failed check writes `status` = `error`. |

## Import a template

1. In n8n open **Workflows**, then **Create workflow**, then the **...** menu, then **Import from File** (or paste the JSON onto the canvas).
2. Create the credential below and select it in every node named *Check ...*.
3. Open the **Settings** node and set the value (see below).
4. Connect the trigger and destination credentials (Telegram, WooCommerce or Google Sheets), then activate the workflow.

Each workflow's canvas carries a yellow overview note with three sections (How it works, Setup, Customization), white section notes that label groups of nodes, and, where a node needs your input, a red warning note.

The credential value must be `Bearer ` followed by a space and your key; pasting only `fn_...` fails with a 401.

**Sheet template:** the sheet needs the header columns `phone`, optional `country`, `valid`, `line_type`, `carrier`, `fraud_score`, `risky`, `checked_at` and `status`. Format the `phone` column as plain text (or include `+` and the country code) so leading zeros are not lost.

## The credential

Create one **Header Auth** credential in n8n named exactly `FraudNova API`:

- **Name:** `Authorization`
- **Value:** `Bearer fn_your_api_key_here`

Get a key under **Dashboard, API keys** at [fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys). Keys start with `fn_` and are shown once.

## The threshold

The lead gate and the WooCommerce workflow have a **Settings** node with `risk_threshold`, default **75** (the top of the "suspicious" band). Lower it for strict offers, raise it (for example 85) to act only on the worst traffic. The [fraud score thresholds guide](https://fraudnova.com/guides/fraud-score-thresholds-explained) explains how to choose. The sheet workflow has no decision to make; its Settings node holds `default_country`, used for rows that have no `country` value.

## What a failed check does

Every HTTP Request node has *Continue on fail* on and every decision first checks `success === true`. If a check fails (no credits left (402), rate limit (429), bad input (422) or an upstream error (502)), it is treated as "not checked", never as risky: the lead gate accepts the lead with `scores: { "email": null, "ip": null }`, the WooCommerce workflow does not alert on what it could not check, and the sheet row is marked `error`.

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
1. Create a Header Auth credential named `FraudNova API` with header `Authorization` and value `Bearer ` plus your key.
2. Set `risk_threshold` in the Settings node (default 75).
3. POST `{ "email", "ip" }` to the webhook URL from your site or another workflow.
4. Each lead uses 2 credits.

## Who it is for
Marketers, affiliates and developers who collect leads through their own form handler and want to filter disposable emails and proxy traffic before the lead reaches a CRM or a network.
```

### WooCommerce order risk alerts on Telegram

```markdown
# Get a Telegram alert for risky WooCommerce orders with FraudNova

## What it does
Runs when WooCommerce creates an order and checks the billing phone, the billing email and the customer IP with the FraudNova API. If any score reaches your threshold, the phone is VOIP or risky, the email is disposable, or the IP is a proxy, VPN or Tor exit, it sends a Telegram message with the order number, total, customer name, the three scores and the flags. Other orders end quietly, and a failed check never triggers an alert.

## Setup
1. Create a Header Auth credential named `FraudNova API` with header `Authorization` and value `Bearer ` plus your key.
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
1. Create a Header Auth credential named `FraudNova API` with header `Authorization` and value `Bearer ` plus your key.
2. Add the header columns `phone`, `country` (optional), `valid`, `line_type`, `carrier`, `fraud_score`, `risky`, `checked_at` and `status`, and format `phone` as plain text.
3. Connect Google Sheets and pick the document and sheet in both Google Sheets nodes.
4. Each row uses 1 credit. Rows are matched on `phone`.

## Who it is for
Anyone cleaning a lead or contact list who wants to know which numbers are real, mobile or VOIP before running an SMS or call campaign.
```

## Check the files

`python3 validate.py` verifies the structure of every template (valid JSON, required node keys, connections, credentials by name only, both webhook responders connected, failed checks never read as risky) and the sticky-note rules (one yellow overview with the three headings and 100 to 300 words, white section notes, short red warnings).

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
