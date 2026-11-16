# Tests

- `php tests/unit.php` — webhook signature, order mapping rules and price markup maths, no WordPress needed.
- `node tests/e2e/run.mjs` — boots WordPress Playground with WooCommerce and this plugin, points it at a local mock of the Fetch Partner API (`mock-fetch.mjs`), and runs the full flow: product sync, stock change, price markup resync, order import, accept, signed/unsigned webhooks. Needs internet the first time (downloads Playground + WooCommerce). Takes ~3–5 minutes.
