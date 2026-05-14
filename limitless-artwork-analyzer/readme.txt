=== Limitless Artwork Analyzer ===
Contributors: limitless
Tags: woocommerce, artwork, png, upload, dpi
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later

Adds a PNG artwork upload and analyzer box to selected WooCommerce product pages.

== Description ==

Limitless Artwork Analyzer is a V1 WooCommerce plugin for local development and iteration. It adds a PNG-only upload box above the Add to Cart button, uploads the file with AJAX, analyzes the PNG server-side with PHP, and saves the analysis result to cart and order item metadata.

The analyzer currently checks:

* PNG file validation by MIME/signature, not extension alone.
* Pixel width and height.
* PNG DPI metadata from the pHYs chunk when available.
* Assumed 300 DPI when metadata is missing.
* Print dimensions in inches.
* DPI quality rating.
* Transparent background based on edge/corner pixels.
* Semi-transparent pixels using a sampled scan.
* Product dimension warning.
* Long PNG warning.

This is intentionally a plugin-first build, not a standalone web app. The analyzer logic is isolated in `includes/class-analyzer.php` so a future version could move upload/storage/analysis to an external service without rewriting the WooCommerce UI and metadata pieces.

== Installation ==

1. Copy the `limitless-artwork-analyzer` folder into your LocalWP site's `wp-content/plugins/` directory.
2. In WordPress admin, go to Plugins.
3. Activate "Limitless Artwork Analyzer".
4. Make sure WooCommerce is active.
5. Go to WooCommerce > Artwork Analyzer.
6. Enable the analyzer and optionally enter comma-separated product IDs.
7. Save settings.

If Product IDs is left blank, the analyzer appears on all WooCommerce products while enabled. This is useful for local testing.

== LocalWP Testing Instructions ==

1. Start your LocalWP site.
2. Open the site's WordPress admin.
3. Confirm WooCommerce is installed and active.
4. Copy this plugin folder into:
   `app/public/wp-content/plugins/limitless-artwork-analyzer/`
5. Activate the plugin.
6. Visit WooCommerce > Artwork Analyzer and save the default settings.
7. Open a WooCommerce product page on the frontend.
8. Upload a PNG in the analyzer box.
9. Confirm the file uploads without a page refresh.
10. Add the product to the cart.
11. Confirm the artwork metadata appears in the cart and checkout.
12. Place a test order and confirm the metadata appears on the WooCommerce order item in admin.

== WooCommerce Test Setup ==

1. Create or edit a simple product in Products > Add New.
2. Add a product name, price, and publish it.
3. Note the product ID from the product edit URL if you want to restrict the analyzer to this product.
4. Go to WooCommerce > Artwork Analyzer.
5. Enter that product ID in Product IDs, or leave Product IDs blank to show the analyzer on all products.
6. Open the product page and test uploads.

== Testing Checklist ==

Upload behavior:

* No file selected shows a friendly message.
* PNG uploads successfully.
* JPG, PDF, SVG, AI, ZIP, and renamed files are rejected.
* Large files over the WordPress upload limit are rejected.
* Corrupt PNGs show a friendly error.
* Missing GD support shows a friendly error.

Analysis behavior:

* Pixel width and height display.
* DPI displays from PNG metadata when available.
* Missing DPI metadata displays: "DPI metadata not found, assuming 300 DPI."
* Print size in inches displays.
* DPI quality rating displays as Poor, Fair, Good, or Excellent.
* Transparent background result displays.
* Semi-transparent pixel warning displays when sampled pixels have alpha between 0 and the configured threshold.
* Dimension warning displays when neither dimension is between 19 inches and 22.5 inches.
* Long file warning displays when either print dimension is over 100 inches.
* Success message displays when there are no warnings.

WooCommerce behavior:

* Analyzed file token is included with Add to Cart.
* Cart item data includes artwork details.
* Checkout item data includes artwork details.
* Admin order item meta includes artwork details and uploaded file URL/path.
* Adding the same product with different artwork creates separate cart lines.

== Notes for Future V2 Improvements ==

* Support PDF, AI, SVG, JPG, and ZIP after the PNG flow is stable.
* Add stricter checkout blocking rules if required.
* Add customer-facing upload requirements per product.
* Move file storage to cloud storage.
* Move analysis to a standalone analyzer API.
* Add a background job for very large artwork files.
* Add admin cleanup tools for old uploaded files.
* Add per-product settings instead of only comma-separated product IDs.
* Add stronger alpha/transparency reporting with full-file scans where server resources allow it.
* Add customer account/order download controls for uploaded artwork.
