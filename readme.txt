=== Fetch for WooCommerce ===
Contributors: fetchcy
Tags: woocommerce, marketplace, delivery, cyprus, fetch
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.9
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell your WooCommerce products on Fetch, Cyprus's delivery marketplace, without managing a second catalogue.

== Description ==

Fetch for WooCommerce connects your shop to [Fetch](https://fetch.com.cy) through the Fetch Partner API.

* **Products, prices and stock sync automatically.** Simple and variable products are sent to Fetch when you create or change them, and again whenever their stock changes — including when someone buys on your own website. Fetch lists each variation as its own product (for example "Logo T-shirt – Black / M").
* **Fetch orders become WooCommerce orders.** Each Fetch order appears in your normal WooCommerce Orders list, tagged as paid on Fetch, and reduces your stock like any other order.
* **Accept, mark ready or reject from WooCommerce.** A "Fetch" box on the order screen sends your decision straight to Fetch, which books the courier.
* **Optional 1:1 mirror.** Choose **Mirror this shop 1:1** and your Fetch store offers exactly what this shop sells: anything else in it is switched off on Fetch (never deleted).
* **Instant or polled.** Register the webhook and new orders arrive within seconds; without it the plugin checks for orders every 2 minutes.

== Installation ==

1. Ask Fetch for a Partner API key for your store (developer.fetch.com.cy). You get a client ID and a client secret.
2. Upload and activate the plugin. WooCommerce must be active.
3. Go to **WooCommerce → Fetch**, enter the client ID and secret, and press **Connect & load stores**. Pick your Fetch store and save.
4. Press **Sync all products now** to send your catalogue the first time.
5. Press **Register webhook** so orders arrive instantly (your site must use https).
6. Optional: turn on **Auto-accept** and set your usual preparation time.

== Frequently Asked Questions ==

= How are my products matched with Fetch? =

By SKU. A product's SKU (or, for variable products, each variation's SKU) is its reference on Fetch. Fetch lists every variation as its own product, named after the parent and its attributes. Products without a SKU use `wc-<product id>`. The reference is remembered after the first sync, so changing a SKU later updates the same Fetch product instead of creating a second one.

= Which prices does Fetch show? =

Fetch prices are what the customer pays, VAT included. The plugin sends your regular and sale prices including tax, using your WooCommerce tax settings, so it works whether you enter prices with or without VAT.

= Can I charge more on Fetch than on my website? =

Yes. Set **Price markup (%)** under WooCommerce → Fetch. It is added to every price and sale price sent to Fetch: 10 sends a €10.00 product as €11.00, rounded to the cent. Your WooCommerce prices are not changed. Changing the markup resends all products to Fetch automatically. Imported Fetch orders keep the prices the customer paid on Fetch.

= What does "Mirror this shop 1:1" do? =

Under **WooCommerce → Fetch → Catalogue mode** you can choose:

* **Add and update only** (the default): products from this shop are added to Fetch and kept up to date. Anything else in your Fetch store is left as it is.
* **Mirror this shop 1:1**: at the end of every full sync (**Sync all products now**, or the resync after a markup change), products in your Fetch store that aren't in this shop are switched off on Fetch (not deleted). That includes products added in the Fetch portal, products this shop excludes (drafts, hidden products, product types the plugin does not sync) and products you deleted. Prices and stock always follow this shop.

Nothing is ever deleted, so it is reversible: a product you bring back is switched on again by the next sync. Product names, photos and descriptions come from Fetch's shared catalogue and are not overwritten; 1:1 means which products are on sale, their price, stock and availability.

Press **Preview** first to see how many Fetch products would be switched off, with up to 20 names. Safety checks: nothing is switched off if the sync could not reach Fetch for every product or if any page of your Fetch store could not be read, and a run that would switch off more than half of your active Fetch products waits until you press **Apply anyway**. Every run is logged under Recent activity.

= What about stock? =

If WooCommerce manages stock for a product, Fetch gets the exact quantity. If it does not, the product sells on Fetch without a stock limit, and "Out of stock" switches it off on Fetch.

= What happens when a Fetch order comes in? =

It becomes a WooCommerce order with status *On hold* while it waits for you to accept it. Accepting moves it to *Processing*; when Fetch delivers it, it becomes *Completed*. If you reject it or Fetch cancels it, the order is cancelled and the stock goes back.

= Webhook or polling? =

Both run. The webhook delivers new orders in seconds and is verified with a signature (HMAC-SHA256, 5-minute replay window). The 2-minute poll is a safety net, so an order is never missed if your site was briefly unreachable. Each Fetch order is imported once, however it arrives.

= Where do I see what happened? =

WooCommerce → Fetch shows recent activity. The full log is under WooCommerce → Status → Logs, source "fetch". Each product also stores its last sync result.

== Changelog ==

= 1.2.0 =
* New: **Catalogue mode** setting with **Mirror this shop 1:1**. At the end of a full sync, every product in your Fetch store that this shop does not sell is switched off on Fetch (never deleted); products that come back are switched on again by the normal sync. Includes a **Preview**, a confirmation step when more than half of your active Fetch products would be switched off, and a log of every run. The default, **Add and update only**, keeps today's behaviour.
* Fix: every product sync request now carries a fresh idempotency key. Sandbox keys replay a repeated key without applying it, so a product switched off and back on could stay off in the sandbox.

= 1.1.1 =
* Fix: large syncs are split into requests of at most 200 products and under 500 KB, the limits of a live Fetch store. A variable product with many variations no longer fails to sync.
* Fix: deleting a product (or a single variation) now switches it off on a live Fetch store. Fetch needs the full product, price included; the plugin used to send only the name.
* Fix: a failed product sync is retried for variable products too, and a failed switch-off is no longer dropped.
* Fix: an order imported after Fetch had hidden the customer's details (Fetch does this 2 hours after the order) no longer shows "******"; the details are filled in when Fetch sends them, and real details are never replaced by hidden ones.
* Fix: the webhook and the 2-minute poll can no longer create the same Fetch order twice when they arrive at the same moment.

= 1.1.0 =
* New: **Price markup (%)** setting. Adds a percentage to every price and sale price sent to Fetch without changing your WooCommerce prices. Changing it resends all products to Fetch automatically.
* New: **Staging** server option (api-stg.fetch.com.cy) for keys Fetch has moved to staging. Sandbox keys keep using Production. The custom URL field now only shows when "Custom URL" is selected.

= 1.0.0 =
* First release: product, price and stock sync; order import with accept, ready and reject; signed webhooks with polling fallback.
