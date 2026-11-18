# Fetch for WooCommerce

Sell your WooCommerce products on [Fetch](https://fetch.com.cy), Cyprus's delivery marketplace, without managing a second catalogue.
The plugin keeps your products, prices and stock in sync with your Fetch store, and brings Fetch orders into WooCommerce so you can handle them where you already work.

It uses the [Fetch Partner API](https://developer.fetch.com.cy).

## Features

- **Automatic product sync.** New products, edits, price changes and stock changes are sent to Fetch within about 20 seconds, including stock changes caused by orders on your own website.
- **Fetch orders as WooCommerce orders.** Each Fetch order appears in your normal Orders list, marked as paid on Fetch, and reduces your stock like any other order.
- **Accept, mark ready or reject from WooCommerce.** A **Fetch** box on the order screen sends your decision straight to Fetch, which books the courier.
- **Instant order alerts with a safety net.** Orders arrive within seconds through a signed webhook, and the plugin also checks every 2 minutes so nothing is missed.
- **Optional 1:1 mirror.** With **Mirror this shop 1:1**, your Fetch store offers exactly what this shop sells: anything else is switched off on Fetch, never deleted.
- **Optional auto-accept** with your usual preparation time.
- **Several Fetch stores from one site.** One shop can feed every branch your Partner API key covers, each with its own markup, catalogue mode, categories and order settings. See [Several Fetch stores from one site](#several-fetch-stores-from-one-site).

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
4. Enter your client ID and client secret, save, then press **Connect & load stores**, tick **Connect** next to each Fetch store this shop sells in, and save.
5. Press **Sync all products now** to send your catalogue the first time.
   Large catalogues are sent in the background in batches of 50.
6. Press **Register webhook** so new orders arrive instantly.
7. Optional: turn on **Auto-accept** and set your usual **Preparation time**.

## Settings

| Setting | What it does |
|---|---|
| Server | **Production** (`https://api.fetch.com.cy`, the default). Live keys, and sandbox keys Fetch issues for testing, both use Production: your key decides whether you see test data. **Staging** (`https://api-stg.fetch.com.cy`) only for keys Fetch has moved to staging. **Custom URL** only if Fetch gives you a different address. Changing the server or the client ID signs in again and asks you to pick your stores again. |
| Client ID / Client secret | Your Partner API key. The secret is stored in your WordPress database and is never shown again after saving. |
| Fetch stores | After **Connect & load stores**, every Fetch store your key can access, each with a **Connect** checkbox. Tick every store this shop sells in. The first time, the first store is ticked for you; after that your choice is kept. |
| Sync to Fetch | Turns automatic product, price and stock sync on or off. Multilingual shops (WPML, Polylang) sync products in the default language only; see [Multilingual shops](#multilingual-shops). |
| Catalogue mode | **Add and update only** (default): products from this shop are added to Fetch and kept up to date; anything else in your Fetch store is left as it is. **Mirror this shop 1:1**: products in your Fetch store that aren't in this shop are switched off on Fetch (not deleted) at the end of every full sync. Prices and stock always follow this shop. **Preview** shows what would change first. See [Mirror this shop 1:1](#mirror-this-shop-11). |
| Brand attribute | **Automatic** (default): the brand comes from a brand taxonomy (WooCommerce Brands, Perfect Brands, YITH Brands), else from any attribute named Brand, Brands or Μάρκα. Choose one of your global attributes if your shop keeps the brand somewhere else; products without it fall back to Automatic. Changing it resends all products to Fetch automatically. See [Brand, specifications and SEO](#brand-specifications-and-seo). |
| Price markup (%) | 0–100, default 0. Added to every price and sale price sent to Fetch, e.g. 10 sends a €10.00 product as €11.00. Your WooCommerce prices are not changed. Changing it resends all products to Fetch automatically. |
| Import Fetch orders | Turns order import on or off. |
| Auto-accept | Accepts new Fetch orders automatically, using the preparation time below. |
| Preparation time | Minutes you need to prepare an order. The courier is booked to collect after this. |
| Register webhook | Registers your site with Fetch for instant order alerts and stores the signing secret. Press it again to issue a new secret. One webhook serves every store on your key. |
| Store status | Under each connected store: the last full sync, how many products were sent, the last order, and how many products have a sync error (with the last error). |

With two or more stores connected, each store also has its own **Price markup**, **Catalogue mode** (with its own **Preview**), **Products** (all, or only some categories), **Auto-accept** and **Preparation time**.
A blank field uses the setting above.
Credentials, server, brand attribute, **Sync to Fetch** and **Import Fetch orders** always apply to every store.
See [Several Fetch stores from one site](#several-fetch-stores-from-one-site).

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
| Name, description, short description | Name, description, short description (plain text, paragraphs kept) |
| Regular price, sale price | Price and sale price, **VAT included** (calculated with your WooCommerce tax settings), plus your **price markup** if you set one, rounded half-up to the cent |
| Stock (managed) | Exact quantity |
| Stock (not managed) | Sold without a stock limit; **Out of stock** switches it off |
| Published and visible in the catalogue | Active on Fetch |
| Draft, private, hidden or trashed | Inactive on Fetch |
| Permanently deleted (product or variation) | Inactive on Fetch |
| Main image and gallery | Photos (set when the product is first created on Fetch) |
| Category | Category path, matched against Fetch's categories |
| Tags, brand, GTIN/EAN | Tags, brand, barcode |
| Attributes shown on the product page | Specifications |
| Yoast SEO / Rank Math title and meta description | SEO title and description |

### Brand, specifications and SEO

- **Brand.**
  The **Brand attribute** setting if you chose one, then a brand taxonomy (`product_brand`, `pwb-brand`, `yith_product_brand`), then any attribute, global or custom, whose taxonomy is `pa_brand`/`pa_brands` or whose label is Brand, Brands or Μάρκα.
  The first value is used.
- **Specifications.**
  Every attribute with **Visible on the product page** ticked and **Used for variations** not ticked, global or custom, in the product's attribute order.
  The name is the attribute's label (a global attribute's label, not its slug); the value is its terms or values joined with ", ".
  The brand attribute is included too.
  At most 100 per product, names up to 100 characters and values up to 1,000.
- **Descriptions.**
  HTML is converted to plain text with paragraphs kept as blank lines and `<br>` and list items as line breaks.
  When a product has no description, its short description is sent as the description too.
- **SEO.**
  The SEO title and meta description from **Yoast SEO** (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`) or **Rank Math** (`rank_math_title`, `rank_math_description`), with the plugin's variables filled in when it is active.
  Empty values are not sent.

Fetch decides, per store, how these appear on your Fetch store.

### Multilingual shops

With **WPML** (with or without WooCommerce Multilingual) or **Polylang**, each translation is a separate WooCommerce product, often with its own SKU (`CCAF 011-el`) or the original's.
The plugin syncs only products in your site's **default language**, with their variations, and skips translations everywhere:

- the full sync (**Sync all products now**) and the resync after a markup change;
- product saves and stock changes;
- deleting or trashing: removing a translation never switches off the original, even when they share a SKU;
- **Mirror this shop 1:1** and its **Preview**: listings that came from translations are not kept, so they are switched off like any other listing this shop does not sell.

Products with no language assigned count as default-language.
Fetch orders are matched to the default-language product, so stock is reduced on the original (WPML usually syncs stock across translations anyway).
**WooCommerce → Fetch** shows the synced language, for example "Synced language: English (default)".
The language comes from the plugins' public APIs (`wpml_default_language` and `wpml_element_language_details`, or `pll_default_language()` and `pll_get_post_language()`); without either plugin nothing changes.

**TranslatePress** and **Weglot** keep translations inside the original product, so they need nothing.

### Variable products

Fetch lists each variation as its own product.
A T-shirt with sizes S, M and L becomes three Fetch products, for example **"Logo T-shirt – Black / M"**, each with its own SKU, price, stock and image.
The parent's descriptions, brand, category, tags, specifications and SEO text are shared by all of them.

### Timing

- Changes are collected and sent about 20 seconds after the last edit, in batches, so bulk edits and busy checkouts become a few API calls.
- If Fetch cannot be reached, the products stay queued and are retried hourly.
- Each product stores its last sync result in its `_fetch_sync_status` and `_fetch_sync_error` fields.

### Mirror this shop 1:1

With **Catalogue mode → Mirror this shop 1:1**, your Fetch store ends up offering exactly the products this shop syncs.

- Every product this shop syncs is sent as usual: price (with your markup), stock and availability.
- Every other listing in your Fetch store is **switched off** on Fetch (`isActive: false`), never deleted:
  - listings with no reference, for example products added in the Fetch merchant portal;
  - listings whose reference is not one of this shop's;
  - listings for products this shop excludes: drafts, private or hidden products, and product types the plugin does not sync (grouped, external);
  - listings for products you deleted.
- Switching off is reversible.
  A product that comes back is switched on again by the normal sync.
- Names, photos and descriptions belong to Fetch's **shared catalogue**, which other stores use too, so they are not overwritten.
  "1:1" means which products are on sale, and their price, stock and availability.

**When it runs:** only at the end of a full sync, that is **Sync all products now** or the automatic resync after a price markup change.
Single product edits handle their own product as before.

**Safety checks:**

| Check | What happens |
|---|---|
| The full sync could not reach Fetch for every product (connection or sign-in error) | The mirror step is skipped; nothing is switched off. Products Fetch rejected one by one do not count. |
| Any page of your Fetch store's listings could not be read | The mirror step is aborted; nothing is switched off. |
| The run would switch off more than half of your active Fetch products | Nothing is switched off. A notice in wp-admin asks you to confirm once with **Apply anyway**. If more products would be switched off by the time it runs, it asks again. |

**Preview** (next to the setting) reads your Fetch store without changing anything and shows "N of M Fetch products would be switched off", with up to 20 names.
Use it before you turn the mode on.

Every run is logged under **Recent activity** with the number of Fetch products scanned, kept, switched off and any errors.

Under the hood the plugin pages through `GET /api/v1/partner/stores/{storeId}/listings` (500 per page, following `nextCursor`), then sends `POST /api/v1/partner/stores/{storeId}/listings/batch` in requests of at most 200 rows and 450 KB.
Each row is addressed by `listingId` and carries the listing's current `price`, which a live store requires, with `isActive: false`.

## Several Fetch stores from one site

A chain with one WooCommerce site and several Fetch stores (branches) on one Partner API key can feed all of them from the same site.
Several stores need the Fetch API update that scopes product codes (references) per store, which Fetch is rolling out alongside this release.

1. Press **Connect & load stores** and tick **Connect** next to each branch.
2. Save.
   Each store you add next to an existing one gets your whole catalogue straight away.
3. Optional: under **Fetch stores**, give a store its own price markup, catalogue mode, categories, auto-accept or preparation time.

**Stock and prices are shared in this version.**
Every store sells from the same WooCommerce products, with the same stock and the same base prices; only a store's own **Price markup** changes what it charges.
There is no per-branch stock: an order from any store reduces the one WooCommerce stock, and the new quantity is then sent to every store.

**Products.**

- Every product change is sent to each connected store that takes it.
  A store with categories chosen takes only products in those categories or their subcategories; a store with none takes every product.
- A product that leaves a store's categories is switched off in that store (not deleted) and stays on in the others.
- Each store has its own queue and full sync.
  If one store cannot be reached, the others carry on and its products are retried later.
- A product has the same reference (SKU) in every store.
  Each store's Fetch product id is kept apart: `_fetch_product_id` for the first store you connected, `_fetch_product_id_<store id>` for the others; likewise `_fetch_sync_status` and `_fetch_sync_error`.
- **Mirror this shop 1:1** works per store: it switches off only that store's listings that it does not get from this shop, and never touches another store.
- Changing a store's markup or categories resends all products to that store only.
  Changing the default markup resends to every store that uses it, and changing the brand attribute resends to every store.

**Orders.**

- Orders from every connected store are imported, by webhook and by the 2-minute check of each store.
- Each order shows its store: a **Fetch store** line in the **Fetch** box, and a **Fetch store** column in the orders list.
- **Accept**, **Mark ready** and **Reject** go to the order's own store, and auto-accept and preparation time follow that store's settings.
- Your key has one webhook URL and signing secret for all its stores.
  The plugin routes each delivery by its store and ignores (and logs once a day) orders of stores you have not connected.

**Upgrading from 1.3.0** needs nothing: your store becomes the first connected store with your current settings, and its products keep the fields they already have, so nothing is sent twice.

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

Imported orders keep Fetch's prices, which are what the customer paid, including any price markup.

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
| Orders from one branch do not arrive | Tick **Connect** for that store under **Fetch stores**. Orders of stores that are not connected are ignored; **Recent activity** says so once a day. |
| Webhook returns 401 | Press **Register webhook** again to refresh the signing secret. |

The full log is under **WooCommerce → Status → Logs**, source `fetch`.

## Data and privacy

- The plugin talks only to the Fetch API server you configure.
- Your client secret and webhook signing secret are stored in the WordPress options table.
- Customer details on imported orders come from Fetch and are stored like any other WooCommerce order.
  Fetch hides a customer's name, phone and address 2 hours after the order; an order imported after that shows "Fetch customer", and the details are filled in if Fetch sends them later.
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

## Changelog

### 1.4.0

- New: **several Fetch stores from one site.**
  After **Connect & load stores**, tick every Fetch store this shop sells in.
  Products, prices and stock go to each of them, and their orders come here, tagged with the store.
- New: per-store **price markup**, **catalogue mode** (with **Mirror this shop 1:1** and **Preview** per store), **category filter**, **auto-accept** and **preparation time**, each falling back to the global setting.
- New: a status line per store: last full sync, products sent, last order and sync errors.
- New: a **Fetch store** line in the order's **Fetch** box, and a **Fetch store** column in the orders list when more than one store is connected.
- Each store has its own sync queue and full sync, so one store's API error does not hold up the others.
- Stock and prices are shared across stores; only the markup can differ.
- Upgrading from 1.3.0 needs no changes: your store stays connected with the same settings and product data.
- Several stores need the Fetch API update that scopes product codes (references) per store, which Fetch is rolling out alongside this release.

### 1.3.0

- New: product **specifications**.
  Every attribute shown on the product page, global or custom, is sent to Fetch with its label and values, in your order.
  Variations sent as separate Fetch products carry their parent's specifications.
- New: the product's **short description**, and its **SEO title and meta description** from Yoast SEO or Rank Math (variables filled in), are sent to Fetch.
- New: **Brand attribute** setting.
  The brand is now also found in any attribute named Brand, Brands or Μάρκα (for example a global "Brands" attribute), global or custom; choose another attribute if your shop keeps the brand elsewhere.
  Changing it resends all products to Fetch.
- Fix: descriptions keep their paragraphs and line breaks (and decode `&amp;` and similar) instead of being flattened into one line.

Earlier versions are listed in [readme.txt](readme.txt).

## Support

Contact Fetch merchant support, or open an issue in this repository.

## License

GPL-2.0-or-later.
See [LICENSE](LICENSE).
