# Fetch for WooCommerce

Sell your WooCommerce products on [Fetch](https://fetch.com.cy), Cyprus's delivery marketplace, without managing a second catalogue.
The plugin keeps your products, prices and stock in sync with your Fetch store, and brings Fetch orders into WooCommerce so you can handle them where you already work.

It uses the [Fetch Partner API](https://developer.fetch.com.cy).

## Features

- **Automatic product sync.** New products, edits, price changes and stock changes are sent to Fetch within about 20 seconds, including stock changes caused by orders on your own website.
- **Fetch orders as WooCommerce orders.** Each Fetch order appears in your normal Orders list, marked as paid on Fetch, and reduces your stock like any other order.
- **Accept, mark ready or reject from WooCommerce.** A **Fetch** box on the order screen sends your decision straight to Fetch, which books the courier.
- **Instant order alerts with a safety net.** Orders arrive within seconds through a signed webhook, and the plugin also checks every 2 minutes so nothing is missed.
- **Optional auto-accept** with your usual preparation time.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.3 |
| WooCommerce | 8.0 (HPOS and legacy order storage are both supported) |
| PHP | 7.4 |
| HTTPS | Required for instant order alerts (webhooks) |
| Fetch | A Partner API key for your store |

## Installation

1. Ask Fetch for a **Partner API key** for your store.
   You receive a **client ID** and a **client secret**.
2. Download this repository as a ZIP (**Code → Download ZIP**), or grab a release, and upload it under **Plugins → Add New → Upload Plugin**.
   Activate it.
3. Go to **WooCommerce → Fetch**.
4. Enter your client ID and client secret, save, then press **Connect & load stores** and choose your Fetch store.
5. Press **Sync all products now** to send your catalogue the first time.
   Large catalogues are sent in the background in batches of 50.
6. Press **Register webhook** so new orders arrive instantly.
7. Optional: turn on **Auto-accept** and set your usual **Preparation time**.

## Settings

| Setting | What it does |
|---|---|
| Server | **Production** (`https://api.fetch.com.cy`) for live stores. **Custom URL** only if Fetch gives you a different address, for example for testing. |
| Client ID / Client secret | Your Partner API key. The secret is stored in your WordPress database and is never shown again after saving. |
| Fetch store | The Fetch store your WooCommerce products and orders belong to. |
| Sync to Fetch | Turns automatic product, price and stock sync on or off. |
| Import Fetch orders | Turns order import on or off. |
| Auto-accept | Accepts new Fetch orders automatically, using the preparation time below. |
| Preparation time | Minutes you need to prepare an order. The courier is booked to collect after this. |
| Register webhook | Registers your site with Fetch for instant order alerts and stores the signing secret. Press it again to issue a new secret. |

## How products are synced

### Matching

Every product is matched on Fetch by a **reference**:

- the product's **SKU**, or the **variation's SKU** for a variable product;
- `wc-<product id>` when there is no SKU.

The reference is remembered after the first successful sync.
Changing a SKU later updates the same Fetch product instead of creating a second one.

If you sell a product that Fetch already carries in its shared catalogue (matched by barcode), only **your price, stock and availability** are updated.
Fetch maintains the name, photos and category of shared catalogue products.

### What is sent

| WooCommerce | Fetch |
|---|---|
| Name, description | Name, description (HTML is stripped) |
| Regular price, sale price | Price and sale price, **VAT included** (calculated with your WooCommerce tax settings) |
| Stock (managed) | Exact quantity |
| Stock (not managed) | Sold without a stock limit; **Out of stock** switches it off |
| Published and visible in the catalogue | Active on Fetch |
| Draft, private, hidden or trashed | Inactive on Fetch |
| Main image and gallery | Photos (set when the product is first created on Fetch) |
| Category | Category path, matched against Fetch's categories |
| Tags, brand, GTIN/EAN | Tags, brand, barcode |

### Variable products

Fetch lists each variation as its own product.
A T-shirt with sizes S, M and L becomes three Fetch products, for example **"Logo T-shirt – Black / M"**, each with its own SKU, price, stock and image.
The parent's description, brand, category and tags are shared by all of them.

### Timing

- Changes are collected and sent about 20 seconds after the last edit, in batches, so bulk edits and busy checkouts become a few API calls.
- If Fetch cannot be reached, the products stay queued and are retried hourly.
- Each product stores its last sync result in its `_fetch_sync_status` and `_fetch_sync_error` fields.

## How orders work

1. A customer orders from your store on Fetch.
2. The order appears in WooCommerce as **On hold**, with the customer's name, phone, delivery address, note, items and delivery fee.
   Items are linked to your products by SKU, and your stock is reduced.
3. Open the order and use the **Fetch** box:
   - **Accept order** with the minutes you need to prepare it;
   - **Mark ready for pickup** when it is packed;
   - **Reject order** with a reason (out of stock, store closed, cannot fulfil, other).
4. Fetch books the courier and handles delivery.
   The WooCommerce order follows along automatically.

| Fetch status | WooCommerce status |
|---|---|
| Waiting for you to accept | On hold |
| Accepted, preparing, ready, out for delivery | Processing |
| Delivered | Completed |
| Cancelled or rejected | Cancelled (stock is restored) |
| Refunded | Refunded |

You must accept a new Fetch order within **15 minutes**.
Auto-accept does this for you.

Items that match no WooCommerce product by SKU are still added to the order by name, with a note, but no stock is reduced for them.

## Webhooks

Fetch sends a signed `POST` to `https://<your-site>/wp-json/fetch/v1/webhook` for these events:

- `order.created`
- `order.status_changed`
- `order.cancelled`

Each request carries an `X-Fetch-Signature` header:

```text
X-Fetch-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with your signing secret>
```

The plugin rejects requests with a wrong signature or a timestamp older than 5 minutes.
Fetch retries failed deliveries with the same `eventId`, and the plugin processes each event once.

If you cannot use webhooks (for example a site without HTTPS), the 2-minute check still imports every order.

## Troubleshooting

| Problem | What to check |
|---|---|
| "Connect & load stores" fails | Client ID and secret, and that the key is a live key for your store. |
| A product does not appear on Fetch | **WooCommerce → Fetch → Recent activity** and the product's `_fetch_sync_error` field show Fetch's reason. |
| A category is not applied | The category path must match a Fetch category. The product is still listed, without that category. |
| Orders arrive late | Register the webhook. Without it, orders arrive within 2 minutes. |
| Webhook returns 401 | Press **Register webhook** again to refresh the signing secret. |

The full log is under **WooCommerce → Status → Logs**, source `fetch`.

## Data and privacy

- The plugin talks only to the Fetch API server you configure.
- Your client secret and webhook signing secret are stored in the WordPress options table.
- Customer details on imported orders come from Fetch and are stored like any other WooCommerce order.
- Uninstalling the plugin removes its settings.
  Orders and product data are kept.

## Development

```bash
# Unit tests (no WordPress needed)
php tests/unit.php

# End-to-end: WordPress Playground + WooCommerce + a mock Fetch API
node tests/e2e/run.mjs
```

See [tests/README.md](tests/README.md) for details.

## Support

Contact Fetch merchant support, or open an issue in this repository.

## License

GPL-2.0-or-later.
See [LICENSE](LICENSE).
