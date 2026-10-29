# Tests

- `php tests/unit.php` — webhook signature + order mapping rules, no WordPress needed.
- `node tests/e2e/run.mjs` — boots WordPress Playground with WooCommerce and this plugin, points it at a local mock of the Fetch Partner API (`mock-fetch.mjs`), and runs the full flow: product sync, stock change, order import, accept, signed/unsigned webhooks. Needs internet the first time (downloads Playground + WooCommerce). Takes ~3–5 minutes.
