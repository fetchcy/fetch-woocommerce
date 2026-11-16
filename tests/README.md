# Tests

- `php tests/unit.php` — webhook signature, order mapping rules, price markup maths, request batching, deactivation rows, masked customer details and the per-order import lock (on in-memory SQLite), no WordPress needed.
- `node tests/e2e/run.mjs` — boots WordPress Playground with WooCommerce and this plugin, points it at a local mock of the Fetch Partner API (`mock-fetch.mjs`), and runs the full flow: product sync, stock change, price markup resync, batches over 200 rows, delete/trash, order import, accept, status changes, masked customer details, webhook + poll races, signed/unsigned webhooks. Needs internet the first time (downloads Playground + WooCommerce). Takes ~3–5 minutes.
