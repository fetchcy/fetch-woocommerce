=== Fetch for WooCommerce ===
Contributors: fetchcy
Tags: woocommerce, marketplace, delivery, cyprus, fetch
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell your WooCommerce products on Fetch, Cyprus's delivery marketplace, without managing a second catalogue.

== Description ==

Fetch for WooCommerce connects your shop to [Fetch](https://fetch.com.cy) through the Fetch Partner API.

* **Products, prices and stock sync automatically.** Simple and variable products are sent to Fetch when you create or change them, and again whenever their stock changes — including when someone buys on your own website. Fetch lists each variation as its own product (for example "Logo T-shirt – Black / M").
* **Fetch orders become WooCommerce orders.** Each Fetch order appears in your normal WooCommerce Orders list, tagged as paid on Fetch, and reduces your stock like any other order.
* **Accept, mark ready or reject from WooCommerce.** A "Fetch" box on the order screen sends your decision straight to Fetch, which books the courier.
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

= What about stock? =

If WooCommerce manages stock for a product, Fetch gets the exact quantity. If it does not, the product sells on Fetch without a stock limit, and "Out of stock" switches it off on Fetch.

= What happens when a Fetch order comes in? =

It becomes a WooCommerce order with status *On hold* while it waits for you to accept it. Accepting moves it to *Processing*; when Fetch delivers it, it becomes *Completed*. If you reject it or Fetch cancels it, the order is cancelled and the stock goes back.

= Webhook or polling? =

Both run. The webhook delivers new orders in seconds and is verified with a signature (HMAC-SHA256, 5-minute replay window). The 2-minute poll is a safety net, so an order is never missed if your site was briefly unreachable. Each Fetch order is imported once, however it arrives.

= Where do I see what happened? =

WooCommerce → Fetch shows recent activity. The full log is under WooCommerce → Status → Logs, source "fetch". Each product also stores its last sync result.

== Changelog ==

= 1.0.0 =
* First release: product, price and stock sync; order import with accept, ready and reject; signed webhooks with polling fallback.
