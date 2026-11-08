// Copy this file to config.mjs and edit it. config.mjs is git-ignored.
export default {
  // Your FraudNova API key. Keep it out of version control: set FRAUDNOVA_API_KEY instead.
  api_key: process.env.FRAUDNOVA_API_KEY ?? 'fn_your_api_key_here',

  // Block when the fraud score is at or above this (0-100).
  threshold: 75,

  // Also block when any of these flags is true. Add 'active_vpn', 'active_tor' or 'recent_abuse' to be stricter.
  block_on: ['proxy', 'vpn', 'tor', 'bot_status'],

  // How strict the scoring is (0-3).
  strictness: 1,

  // Seconds a scored IP is remembered in memory. Saves credits on repeat clicks; resets on restart.
  cache_ttl: 86400,

  // How long to wait for the API, in milliseconds.
  timeout_ms: 2000,

  // If the API fails: true sends the visitor to the money page, false to the safe page.
  fail_open: true,

  // Set true ONLY when the gate sits behind Cloudflare or a reverse proxy you control.
  // Otherwise visitors could fake their IP with a header.
  trust_proxy_headers: false,

  // Optional postback fired when a visitor is blocked. {clickid} etc. are filled from the query string.
  report_url: null, // e.g. 'https://tracker.example/postback?cid={clickid}&status=blocked'

  // Optional log file (one line per click). null = only errors, printed to the console.
  log_file: null,

  // Only these countries reach the money page, e.g. ['PK', 'IN']. Empty = every country.
  allowed_countries: [],

  // Where visitors go. Pick one per campaign with ?route=name; unknown names use "default".
  // {clickid} is replaced with the tracker's click id, and other query params are passed along.
  routes: {
    default: {
      money: 'https://your-offer.example/landing?clickid={clickid}',
      safe: 'https://your-site.example/',
    },
  },
};
