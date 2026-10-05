# CLAUDE.md

Prototype of a proposed WooCommerce core feature: tax categories, per-place category rules, and shipping tax that follows the items. It's packaged as a plugin for testing; the proposal itself is for core.

## Layout

- `woo-core-tax-categories.php`: bootstrap; hooks everything on `plugins_loaded` when WooCommerce is active.
- `src/Engine.php`: pure logic (no WP calls). Category rule matching, shipping rule matching, and the shipping-tax decision flow. Keep it pure so `tests/EngineTest.php` runs with plain `php`.
- `src/Store.php`: options and meta keys. All data is in new keys (`wctc_*` options, `wctc_tax_category` term meta, `_wctc_tax_category` product meta). Never rewrite existing WooCommerce data.
- `src/Resolver.php`: resolves a product's tax category (variation → product → product category → parents) and filters `woocommerce_product_get_tax_class` / `woocommerce_product_variation_get_tax_class` at checkout.
- `src/Shipping.php`: re-taxes rates on `woocommerce_package_rates`, records line meta on the order, and keeps the split on admin Recalculate via `woocommerce_order_item_shipping_after_calculate_taxes`.
- `src/Admin/Settings.php`: Tax options checkboxes (`woocommerce_tax_settings`) and the Tax → Tax categories section.
- `src/Admin/ProductFields.php`: fields and columns on product categories, products and variations.
- `src/Admin/Guide.php` + `assets/guide.{js,css}`: reviewer guide (New badges, dashed outlines, per-screen banner, tour). Off with `add_filter( 'wctc_show_guide', '__return_false' )`.
- `src/Examples.php`: example data matching the scope doc's acceptance examples, plus Illinois and Belgium.
- `docs/`: Markdown exports of the requirements scope, the stress-test findings and the test-case results. `HANDOFF.md` is the session handoff: read it first in a new session.
- `playground/`: `setup.php` builds the test store; `wctc-test.php`, `wctc-stress.php`, `wctc-suite.php` are the in-store test pages; `sqlite-shim.php` is a Playground-only mu-plugin; `launcher.html` is the one-click launcher.
- `tools/checkout.spec.js`: Playwright run through the block checkout.

## Rules

- Opt-in: with both settings off, behavior must match stock WooCommerce exactly.
- Categories never hold rates; rules point at existing tax classes.
- Shipping rule modes map to the explainer's five rules: follows, exempt_stated, conditional, always, delivery_terms. Mixed-basket treatments (`Engine::splits()`): value, weight, lowest (Belgium), majority (Illinois; 50/50 falls back to value).
- Category rules: most specific place wins; among equals the tighter price limit wins; true duplicates (same category, place, limit, dates) are saved but flagged, first row wins. Rows with a blank country, a non-numeric limit or end-before-start are rejected on save with a `WC_Admin_Settings::add_error` message.
- A rule whose tax class has no rates in its place is skipped (product keeps its own class); the Zero rate class is the exception.
- Run `php tests/EngineTest.php` after changing `Engine.php`, and add a case for any new rule.
- Lint: `for f in $(find . -name '*.php'); do php -l "$f"; done`
- Integration test: rebuild `playground/blueprint.json` with `python3 tools/build-blueprint.py`, boot it in WordPress Playground, and open `/wctc-test.php`, `/wctc-stress.php`, then `/wctc-suite.php` (all checks must pass; the suite reuses the stress page's products). Then refresh `playground/launcher.html` (blueprint JSON inside the `#blueprint` script tag, and the "Prototype version <sha>" line).
