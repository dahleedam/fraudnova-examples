# FraudNova n8n workflow templates

Three importable [n8n](https://n8n.io) workflows that add FraudNova fraud checks to an automation you probably already run. They use only n8n's built-in nodes (HTTP Request plus one trigger and one destination each), so there is nothing to install, and they run on n8n Cloud or self-hosted.

| File | Trigger | What it checks | Result |
| --- | --- | --- | --- |
| [`lead-gate-webhook.json`](lead-gate-webhook.json) | Webhook (`POST /webhook/fraudnova-lead-gate`) with `email` and `ip` in the JSON body | Email (`/check/email`) and IP (`/check`) | Responds `{"accepted": false, "reason": "...", "scores": {...}}` when the email is disposable, either score is at or above the threshold, or the IP is a proxy, VPN or Tor exit; otherwise `{"accepted": true, "scores": {...}}`. Works with any form tool that can POST a webhook (Tally, Typeform, WPForms, Elementor, your own site). |
| [`woocommerce-order-risk-telegram.json`](woocommerce-order-risk-telegram.json) | WooCommerce Trigger, `order.created` | Billing phone and country (`/check/phone`), billing email (`/check/email`), customer IP (`/check`) | Sends a Telegram message with the order number, total, customer name, the three scores and the flags when any score is at or above the threshold, the phone is VOIP or risky, the email is disposable, or the IP is a proxy, VPN or Tor exit. Other orders end the run. |
| [`sheet-phone-enrichment.json`](sheet-phone-enrichment.json) | Google Sheets Trigger, row added | Phone from the `phone` column and an optional `country` column (`/check/phone`) | Writes `valid`, `line_type`, `carrier`, `fraud_score`, `risky`, `checked_at` and `status` back to the same row. A failed check writes `status` = `error`. |

## Import a template

1. In n8n open **Workflows**, then **Create workflow**, then the **...** menu, then **Import from File** (or paste the JSON onto the canvas).
2. Create the credential below and select it in every node named *Check ...*.
3. Open the **Settings** node and set the value (see below).
4. Connect the trigger and destination credentials (Telegram, WooCommerce or Google Sheets), then activate the workflow.

Each workflow also carries a sticky note with these four steps.

## The credential

Create one **Header Auth** credential in n8n named exactly `FraudNova API`:

- **Name:** `Authorization`
- **Value:** `Bearer fn_your_api_key_here`

Get a key under **Dashboard, API keys** at [fraudnova.com/dashboard/api-keys](https://fraudnova.com/dashboard/api-keys). Keys start with `fn_` and are shown once.

## The threshold

The lead gate and the WooCommerce workflow have a **Settings** node with `risk_threshold`, default **75** (the top of the "suspicious" band). Lower it for strict offers, raise it (for example 85) to act only on the worst traffic. The [fraud score thresholds guide](https://fraudnova.com/guides/fraud-score-thresholds-explained) explains how to choose. The sheet workflow has no decision to make; its Settings node holds `default_country`, used for rows that have no `country` value.

## What a failed check does

Every HTTP Request node has *Continue on fail* on and every decision first checks `success === true`. If a check fails (no credits left (402), rate limit (429), bad input (422) or an upstream error (502)), it is treated as "not checked", never as risky: the lead gate accepts the lead with `scores: null`, the WooCommerce workflow does not alert on what it could not check, and the sheet row is marked `error`.

## Cost

One credit per check, whichever type. The lead gate uses 2 credits per lead, the WooCommerce workflow 3 per order, the sheet workflow 1 per row. Each workflow checks once per item and never retries. Rate limits and the per-account concurrency cap are in the [docs](https://fraudnova.com/docs).

## Send the alert somewhere else

Replace the last node. In the WooCommerce workflow delete **Telegram alert** and connect the `true` output of **Risky?** to a Slack, Discord, Email or Microsoft Teams node; the same expressions (`$('Check phone').item.json.data.fraud_score` and friends) are available in its message field. In the lead gate, add your CRM or Airtable node after **Respond accepted** to store accepted leads. In the sheet workflow, swap Google Sheets for Airtable or a database node.

## Check the files

`python3 validate.py` verifies the structure of every template (valid JSON, required node keys, connections, credentials by name only, both webhook responders connected, failed checks never read as risky).

## Publishing to n8n's template library

For the maintainer: submit each JSON file through your n8n creator account (<https://creators.n8n.io>). The credential is referenced by name only, so the files carry no ids or secrets.
