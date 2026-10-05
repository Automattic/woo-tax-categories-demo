# Tax Categories for WooCommerce: core prototype

A working prototype of the proposal in **Tax Categories for WooCommerce: Requirements Scope**, so you can try it on a real store. The proposal is a core feature. This is packaged as a plugin only so it's easy to install and remove on a test site.

What it does:

- **Tax categories.** A label for what a product is for tax (Clothing, Groceries, Books), set on product categories, products or variations. Resolution: variation → product → product category (walking up parents). With no category, the product keeps its own tax class.
- **Category rules.** Map a tax category in a place to one of the store's existing tax classes, with an optional per-item price limit and start/end dates. Example: Clothing in US‑NY under $110 → Zero rate (cliff mode). Or Clothing in US‑MA with the first $175 per item exempt and only the excess taxed (excess mode). The most specific matching rule wins. Rates still come from the existing rate table.
- **Shipping tax follows the items.** Each place can have a shipping rule, using the five rules from *How Shipping Tax Works at Checkout*. A mixed basket is split by value, or by weight where the rule says so.
- **Opt-in, no migration.** Two settings, both off by default. Nothing existing is rewritten: data lives in new options and meta keys. With both settings off, totals are identical to today. Deactivating the plugin returns everything to stock behavior.

## Install on a test store

1. Get a zip of this repo: **Code → Download ZIP** on GitHub, or `git archive --format=zip --prefix=woo-core-tax-categories/ -o woo-core-tax-categories.zip HEAD`.
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip, then activate it.
3. Go to **WooCommerce → Settings → Tax → Tax categories**.

Use a test store: the "Add example tax rates" button writes rows to the Standard rate table. They're named "WCTC example …" and "Remove example data and rates" deletes only those rows.

## Try the acceptance examples

1. On **Tax → Tax categories**, click **Load example data**, then **Add example tax rates**.
2. On **Tax → Tax options**, tick **Tax categories**, and make sure **Calculate tax based on** is "Customer shipping address". Save.
3. Set the **Clothing** tax category on a clothing product category (**Products → Categories**, edit the category). Leave a book product with no category.
4. Add a $45 clothing item and a $20 book to the cart, with a Flat rate shipping method at $10. Ship to New York, NY 10001.
   - Expected: hoodie 0% (Clothing in NY under $110 → Zero rate), book $1.78 at 8.875%, shipping tax **$0.27**, total tax **$2.05**. Without the plugin, shipping tax would be $0.89.
5. Change the address to try the other rules:
   - **Arizona:** shipping isn't taxed.
   - **Hawaii:** shipping is taxed in full even with only exempt items.
   - **California:** shipping is exempt because the example rule says the store meets the conditions. Untick "Conditions met" to see it follow the goods.
   - **Minnesota:** shipping is split by weight, so set product weights.
   - **United Kingdom:** VAT on shipping follows the goods.
6. Place the order, then open it in admin. Each line shows **Tax category** and **Tax rule**, and the shipping line shows how its tax was split. Click **Recalculate** and the split is kept.

## Try it in WordPress Playground

Two blueprints boot throwaway WordPress stores in the browser with WooCommerce, this plugin and example data — one aimed at the EU/UK/AU market (the main P2 post), one at the US (state-level exceptions, excess-only limits, the 111-case suite). Both land on **Settings → Tax → Tax categories**. Hosted on Spacefast with CORS enabled.

### EU / UK / AU store (default)

Berlin base, EUR, selling to DE, FR, IT, IE, BE, NL, GB and AU with a €4.90 flat rate. Four categories (Books, Children's clothing, Food, Digital books), country-level rules with gaps on purpose — a children's jumper is Standard in Germany and Zero in Ireland without touching the product. One shipping rule (BE: whole charge at the lowest rate in the box). Full brief in `docs/eu-demo-store-brief.md`.

- One click: [playground.wordpress.net/?blueprint-url=…bright-willow.view.fast/blueprint-eu.json](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fbright-willow.view.fast%2Fblueprint-eu.json)
- **/wctc-eu-check.php**: eight cart cases (Berlin, Dublin, Paris, Brussels, London, Amsterdam, Sydney + opt-out parity) verified against the brief's expected numbers.

### US store (state exceptions)

New York base, USD, selling to US and GB with a $10 flat rate. Keeps the state-level examples (NY cliff under $110, MA/RI excess, CA/HI/AZ/MN/IL shipping rules) and the full validation suite.

- One click: [playground.wordpress.net/?blueprint-url=…bright-willow.view.fast/blueprint.json](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fbright-willow.view.fast%2Fblueprint.json)
- Boot straight to the NYC acceptance example (cart pre-filled, shipping address pinned to New York 10001, lands on checkout showing $2.05 total tax): [playground.wordpress.net/?blueprint-url=…&url=/wctc-acceptance.php](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fbright-willow.view.fast%2Fblueprint.json&url=%2Fwctc-acceptance.php)
- **/wctc-acceptance.php**: pre-fills the cart with the $45 hoodie + $20 book and ships to NYC in one hop.
- **/wctc-test.php**: 25 acceptance checks.
- **/wctc-stress.php**: 16 edge cases.
- **/wctc-suite.php**: 80+ cases including opt-out parity, excess-only limits (MA $175, RI $250), tax-free report. Add `?format=json` for machine-readable output. Run the stress page first; the suite reuses its products.

`playground/launcher.html` in this repo is a 5 KB page with buttons for both.

Playground runs WordPress on SQLite, which lacks `SUBSTRING_INDEX()`; stock WooCommerce's Analytics → Taxes per-rate table is empty there with or without this plugin. The blueprint installs a small mu-plugin (`playground/sqlite-shim.php`) that adds the function so the report can be reviewed. It's a test-store aid, not part of the proposal.

After editing plugin files, rebuild both blueprints with `python3 tools/build-blueprint.py --store all` and republish them to the hosted URL (`.spacefast/space.json` keeps the Space id so the same URLs stay current).

`tools/checkout.spec.js` is a Playwright run through the **block checkout** (not the API) for four reference carts, reading the totals off the page. Point it at a booted store with `WCTC_BASE=https://playground.wordpress.net/scope:…` or let it boot one from the blueprint.

## Reviewer guide

Every field the prototype adds is marked the way the scope doc's mockups are: a dashed purple outline and a **New** badge. Hover or tab to a badge for a short explanation. Each changed screen also gets a "What's new on this screen" banner with how-to steps and a **Take the tour** button, which walks through the new fields one at a time. The banner can be collapsed, and it stays collapsed per screen. To turn the guide off, use `add_filter( 'wctc_show_guide', '__return_false' );`.

## Where things are

| Screen | What's added |
|---|---|
| Settings → Tax → Tax options | "Tax categories" and "Shipping tax split by tax class" checkboxes |
| Settings → Tax → Tax categories | Categories (with provider code), category rules, shipping rules, example data buttons |
| Products → Categories | Tax category field and column (child categories inherit) |
| Products → All Products | Tax category column showing where it comes from |
| Product edit → General | Tax category field + effective tax class preview by place |
| Product edit → Variations | Tax category per variation ("Same as parent" by default) |
| Order edit | Tax category and rule per line; shipping split note; Recalculate keeps the split |
| WooCommerce → Tax-free sales | Date-ranged report of sales that paid no tax because of a rule (zero-rated or the exempt portion of excess-only items), bucketed by tax category |

## How it behaves in the edge cases

- **Shipping follows the goods by default** once tax categories are on, split by value. Shipping rules are only for exceptions: places that exempt shipping, always tax it, or treat a mixed basket differently: split by weight (Minnesota), the whole charge at the lowest rate in the box (Belgium), or at the rate of the majority of the value (Illinois; a 50/50 basket falls back to a value split).
- **Rules that can't work are rejected on save with a message**: no country, a price limit that isn't a number, an end date before the start date. A second rule for the same category, place and limit is saved but flagged on its row, since only the first can apply. Among tiered rules for one place, the tighter price limit wins regardless of row order.
- **Shipping is taxed where WooCommerce taxes the items**: the shipping address, the billing address or the store address, following "Calculate tax based on". Local pickup is taxed at the store.
- **The split uses line totals after discounts.** List prices are used only when discounts take the whole basket to $0.
- **Per-item charges stay with their item.** For Flat rate costs like `8 * [qty]` or `5 + 2 * [qty]`, the per-item part is taxed like its item and only the fixed part is split. Other methods can pass per-item amounts with the `wctc_shipping_item_costs` filter.
- **Orders created or recalculated in admin** resolve tax categories at the order's address, not the admin's.
- **A rule pointing at a tax class with no rates in its place is skipped.** It's flagged in settings ("Rate there") instead of quietly charging 0%. The built-in Zero rate class is the exception, since it's meant to have no rates.
- **Products whose product categories disagree** keep their own tax class and show as a conflict in the products list until a tax category is set on the product.
- **Tax notes are admin-only.** They show on the order screen and never on customer emails or pages.

## Prototype limits

- Carrier conditions (California, South Carolina) are answered per place, not per shipping method. California exempts shipping only when the store uses a common carrier (or USPS), charges no more than actual cost, and lists shipping on its own line; South Carolina has a similar test. A merchant whose shipping varies by method (USPS Priority for most, hand-delivered local pickup) can only answer yes or no for the whole state. The right home for this answer in a core version is probably the shipping method instance with the place rule as a default; estimated at about half a day. Mitigation here: leave the CA/SC rule off and let shipping follow the goods — over-taxes some orders, never under-taxes.
- Handling isn't a separate charge.
- The Tax-free sales report is a sibling admin page under WooCommerce, not an integration into Analytics → Taxes (that would need a React Admin slot and a build step). The data it shows is what a core proposal would surface on the Analytics summary and in a per-category table under the existing rate table.

## Tests

The tax logic lives in `src/Engine.php` with no WordPress calls, and is tested against the acceptance examples:

```
php tests/EngineTest.php
```
