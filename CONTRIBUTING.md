# Contributing

Thanks for helping improve these examples.

- **Scope:** this repository holds runnable client examples for the [FraudNova API](https://fraudnova.com/docs). For API behaviour questions or account issues, contact support through the site instead of opening an issue here.
- **Bugs and fixes:** open an issue or pull request describing the problem and the command you ran. Keep each pull request to one change.
- **New examples:** keep them dependency-light (Python: `requests`; Node.js: built-in `fetch`, Node 18+; PHP: plain `ext-curl`; shell: `curl`). Read the API key from `FRAUDNOVA_API_KEY`, take the subject as a command-line argument, and exit non-zero on an HTTP or API error.
- **Keep docs and examples consistent:** request and response shapes must match the live [OpenAPI spec](https://fraudnova.com/docs/openapi.json) and the [API docs](https://fraudnova.com/docs). If you refresh `openapi.json`, pretty-print it with `python3 -m json.tool`.
- **Secrets:** never commit API keys, webhook secrets or real customer data. Use placeholders such as `fn_...`.
- **Checks before you open a PR:** `bash -n` on shell scripts, `python3 -m py_compile` on Python files, `node --check` on `.mjs` files, and `php -l` on PHP files.

By contributing you agree that your contribution is licensed under the MIT License in this repository.
