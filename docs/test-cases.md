# Tax Categories Prototype: Test Cases and Validation Results

Oct 3, 2026 · @George Jeng

## Summary

111 of 111 automated cases pass on prototype commit `03e4196` in WordPress Playground (WooCommerce 11.1.2), and every opt-out case produced totals identical to stock WooCommerce. The three pages: `/wctc-test.php` (25 acceptance checks), `/wctc-stress.php` (16 edge cases) and `/wctc-suite.php` (70 cases across eight areas, below). Each case builds a real cart or order in the store and reads the numbers back; nothing is mocked.

**Updated 2026-10-03 (second pass).** After the first run, three prototype limits were built out and the merchant UX findings were fixed: Belgium's lowest-rate and Illinois's majority-of-value basket modes (D13–D17), ZIP-level shipping rules (D18), a Playwright run through the block checkout (`tools/checkout.spec.js`), and on the settings screen: country pickers with a state list, typed names mapped to codes (H1b), rejected rows with a message instead of silent fixes (H2, H3, H4), duplicate-rule flags (H9), tiered rules that work in either order (H10), a confirmation before removing a category with rules, and the product preview flagging rules that are skipped for lack of rates. Rows below marked *second pass* changed as a result.

| Area | Cases | Result | What it covers |
| --- | --- | --- | --- |
| A. Opt-out parity | 12 | 12 pass | Both settings off: nine carts identical to stock, product tax class untouched, orders placed and recalculated with no line notes |
| B. Category resolution | 8 | 8 pass | Variation → product → product category → parent; conflicts; shipping-only items |
| C. Rule matching | 8 | 8 pass | Specificity, sale price vs limit, dates, ZIP rules, skipped classes, overlaps |
| D. Shipping tax | 18 | 18 pass | All five rules, split by value and weight, lowest-rate and majority basket modes, ZIP shipping rules, pickup, free shipping, discounts, compound rates, prices incl. tax, per-item formulas |
| E. Order editing | 7 | 7 pass | Recalculate, address change, admin-created order, refunds |
| F. Store API | 1 | 1 pass | Block cart totals match the classic cart |
| G. Analytics | 5 | 5 pass | Tax lookup table, Analytics → Taxes report and stats, legacy tax rows |
| H. Setup mistakes | 11 | 11 pass | Typed names and codes, rejected rows with messages, duplicates, tiered rules, deleted categories, rules with no rates |

Two cases failed on the first run; both were test-harness problems, not plugin bugs. G3 failed because Playground runs on SQLite, which has no `SUBSTRING_INDEX()`; stock WooCommerce's Analytics → Taxes per-rate table is empty on Playground with or without this plugin. G4 compared a string to a longer string. The blueprint now ships a small shim so the report can be reviewed on Playground, and both assertions check numbers.

What the run surfaced that is worth a product decision (details in the UX findings section):

1. Six merchant mistakes were handled silently in the first run. Four are now rejected or flagged with a message (blank country, bad price limit, end before start, duplicate rule); removing a category with rules now asks first; a re-added category name keeps the existing one. Deleting a product category (H8) is still silent, by design: that is a WooCommerce catalogue action, and the products list shows the fallback.
2. Zero-rated and exempt sales are invisible in Analytics → Taxes. That is stock behaviour (the report is keyed by rate row), but the feature makes a lot more sales zero-rated, so a merchant can no longer see how much they sold tax-free. Still open; it is a reporting decision, not a prototype fix.
3. Country and state were free-text fields. Country is now a picker, state offers that country's list, and typed names such as "New York" or "United States (US)" are saved as codes.

## How to run the tests yourself

Open the Playground launcher (the "Tax categories prototype" artifact, version b0f8c06) and wait for the store to land on Settings → Tax → Tax categories. Then type each of these into the Playground address bar, in order:

1. `/wctc-test.php`: the 25 acceptance checks from the requirements scope.
2. `/wctc-stress.php`: 16 edge cases. Run this before the suite; it creates the products the suite reuses (ebook, book tote, variable cookbook).
3. `/wctc-suite.php`: the 61 cases in this document, grouped by area, with steps, expected and actual values. Add `?format=json` for machine-readable output.

Each page is a plain PHP script in the repo under `playground/`, so a case can be read as a reproduction recipe. The test store: based in New York, NY 10001, prices exclusive of tax, tax based on the customer shipping address, a $10 flat rate in a "US and UK" zone, the six example products (hoodie $45, coat $150, jacket $60, book $20, mug $10, blender $40), and the example categories, rules and rates from the scope doc. The suite adds a Quebec compound rate, a NY reduced 2% rate and a few products as it goes, and restores the settings it changes.

**Reading the results.** Expected values are computed by hand from the rate tables (for example $20 × 8.875% = $1.78 for the book in NYC). A case passes when the store's number matches to the cent. Cases that assert a UI state (a column label, a note on the order screen) compare the exact string.

## A. Opt-out parity: nothing changes until a merchant opts in

All 12 pass. With both settings off, every cart below produced the same total tax and shipping tax as a store without the plugin. The harness checks parity the hard way: it computes each cart once with the plugin's hooks removed (as if deactivated), then again with the plugin active and both settings off, and compares.

| ID | Scenario | Expected | Actual | Stock numbers (total tax / shipping tax) |
| --- | --- | --- | --- | --- |
| A1 | NYC hoodie + book | identical | identical | $6.66 / $0.89 |
| A2 | UK book + mug | identical | identical | £8.00 / £2.00 |
| A3 | Arizona blender, rate row has Shipping unticked | identical | identical | $2.24 / $0.00 |
| A4 | Minnesota jacket + blender | identical | identical | $7.57 / $0.69 |
| A5 | NYC with a $20 coupon on the book | identical | identical | $4.88 / $0.89 |
| A6 | NYC local pickup | identical | identical | $6.21 / $0.44 |
| A7 | Billed NY, shipped AZ, tax based on billing address | identical | identical | $6.66 / $0.89 |
| A8 | Quebec compound GST + QST | identical | identical | $11.60 / $1.55 |
| A9 | Tax-exempt customer in NYC | identical | identical | $0.00 / $0.00 |
| A10 | Hoodie has a Clothing category and a NY rule, feature off | Standard (own class) | Standard (own class) | The rule must not leak through when the setting is off |
| A11 | Order placed, then Recalculate in admin, feature off | $6.66 | $6.66 | Hoodie $3.99 + book $1.78 + shipping $0.89 |
| A12 | Line notes on that order | absent | absent | No `_wctc_*` meta is written while the feature is off |

Two things this proves about the design. First, the plugin's hooks are inert when the setting is off, so there is no "half on" state: a merchant who installs core with this feature and never visits the settings sees no difference. Second, the stock shipping tax behaviour (one tax class for all shipping, the `woocommerce_shipping_tax_class` option) is what the plugin falls back to, not a reimplementation of it: A3 confirms the per-rate "Shipping" tick-box still controls shipping tax when the feature is off.

## B. Tax category resolution

All 8 pass. The order of precedence (variation → product → product category → parent categories) holds, a conflict or a dangling reference falls back to the product's own tax class, and the result is reported on the product so a merchant can see where it came from. Values are the item's tax at NYC 8.875% unless stated.

| ID | Scenario | Steps | Expected | Actual | Note |
| --- | --- | --- | --- | --- | --- |
| B1 | Grandchild category inherits | $50 product only in Zip hoodies, two levels under Clothing; ship to NYC | $0.00 | $0.00 | Inherits Clothing; the NY rule zero-rates it |
| B2 | Product's own category beats its product category | Set the hoodie's tax category to Groceries while its category says Clothing | $0.00 | $0.00 | Groceries in NY → Zero rate |
| B3 | Source of the category is reported | Read where the hoodie's category came from | product | product | Products list shows "from product" vs "from category" |
| B4 | Variation inherits the parent product | Cookbook "Paperback" variation with no override, parent in Books; ship to the UK | £0.00 | £0.00 | Books in GB → Zero rate |
| B5 | Clearing a product category's tax category | Clear it on Books, ship a £20 book to the UK | £4.00 | £4.00 | Back to the product's own class: 20% VAT |
| B6 | Product in no product category | $5 product in no category | $0.44 | $0.44 | Own class (Standard) |
| B7 | Product category points at a deleted tax category | Kitchen → slug that no longer exists; ship a $10 mug | $0.89 | $0.89 | Ignored; the mug keeps Standard and the products list says "Own tax class" |
| B8 | Product with tax status "Shipping only" | $12 poster, shipping-only, $10 flat rate | $0.89 shipping tax | $0.89 | The item is untaxed but its shipping is taxable, as the status says |

B8 was a real bug found by this run: the first draft of the shipping split treated only `taxable` items as taxable, so a shipping-only item was dropped from the split. The fix counts both statuses in the cart and order paths.

Not covered here, already covered by the stress page: two product categories that disagree (the product keeps its own class and the list shows a conflict), and a variation with its own category overriding the parent.

## C. Category rule matching

All 8 pass. The most specific rule wins (ZIP prefix over state over country over `*`), the price limit uses the price actually charged, dates are honoured, and a rule whose class has no rates in its place is skipped rather than silently charging 0%.

| ID | Scenario | Steps | Expected | Actual | Note |
| --- | --- | --- | --- | --- | --- |
| C1 | Price limit uses the sale price | Coat, regular $120 on sale for $100; NY clothing rule "under $110" | $0.00 | $0.00 | $100 is under the limit, so exempt. The limit compares the per-item price charged, before tax |
| C2 | ZIP-prefix rule beats the state rule | Add Clothing in NY ZIP `100*` → Reduced (2%) beside the state rule → Zero; ship to 10001 | $0.90 | $0.90 | $45 × 2% |
| C3 | Outside the ZIP prefix the state rule applies | Same rules, ship to 11201 | $0.00 | $0.00 | Zero rate |
| C4 | Expired rule does not apply | NY clothing rule ends in 2020 | $3.99 | $3.99 | Own class: $45 × 8.875% |
| C5 | Future rule does not apply yet | NY clothing rule starts in 2030 | $3.99 | $3.99 |  |
| C6 | Country `*` applies everywhere | Digital books → Zero for country `*`; ship a $15 audiobook to Texas | $0.00 | $0.00 |  |
| C7 | Place codes match regardless of case | Rule state `ny` against address `NY` | match | match | Typed codes are also uppercased on save (H1) |
| C8 | Two rules for the same place | Second NY clothing rule (Reduced, no limit) below the Zero rate one | $0.00 | $0.00 | First row in the table wins. Nothing warns the merchant about the overlap: see UX findings |

C8 passes as specified but is the clearest UX gap in this area. A merchant who adds a second rule for the same category and place, meaning to replace the first, gets the old behaviour with no message. The rule table could flag overlapping rows the way the "Rate there" column already flags classes with no rates.

## D. Shipping tax

All 12 pass. The reference cart is the scope doc's: hoodie $45 (Clothing, exempt in NY) + book $20 (Standard) + $10 flat rate to NYC. Shipping follows the goods split by value, so 20/65 of the $10 is taxed at 8.875% = $0.27. These cases change one thing at a time around that cart.

| ID | Scenario | Steps | Expected | Actual | Note |
| --- | --- | --- | --- | --- | --- |
| D1 | Virtual-only cart | Only a virtual audiobook, ship to NYC | no shipping line, $0.00 | $0.00 | No error; item tax $1.33 |
| D2 | Every offered method is re-taxed, not only the chosen one | Read the tax on Flat rate, Local pickup ($5) and Free shipping | 0.27 / 0.14 / 0.00 | 0.27 / 0.14 / 0.00 | Pickup is taxed at the store's address (NY) |
| D3 | Weight split when one item has no weight | Minnesota (split by weight): jacket 2 lb + $40 lamp with no weight | $0.28 | $0.28 | Falls back to a value split rather than treating the lamp as weightless |
| D4 | Shipping method marked not taxable | Flat rate tax status None, ship to Hawaii (rule: always taxed) | $0.00 | $0.00 | The method's own setting still wins |
| D5 | Prices entered inclusive of tax | Store switched to prices incl. tax | matches net split | pass | The split uses net (ex-tax) line totals, so the book's share is its net price over the net basket |
| D6 | Round tax at subtotal level | Setting on, reference cart | $2.05 total tax | $2.05 |  |
| D7 | Compound rates | Montreal: GST 5% + QST 9.975% compound; no Canadian rules | $1.55 shipping tax | $1.55 | Shipping follows the goods and compounds the same way the items do |
| D8 | Tax based on the store address | Store taxes at its own NY address; customer in Arizona | $0.27 | $0.27 | Rules and shipping follow the address WooCommerce taxes at, not the customer's |
| D9 | Flat rate with a base fee and a per-item amount | `5 + 3 * [qty]` = $11 | $0.40 | $0.40 | The $3 per item stays with its item; only the $5 is split by value |
| D10 | Per-item flat rate with quantities | `8 * [qty]`, 2 hoodies + 1 book = $24 | $0.71 | $0.71 | Only the book's $8 is taxed |
| D11 | 50% coupon on everything | 50% cart coupon on the reference cart | $0.27 | $0.27 | Shares are unchanged, so the split is unchanged |
| D12 | Free shipping chosen | Choose Free shipping | $0.00 | $0.00 |  |

*Second pass* (basket modes and ZIP shipping rules). The Illinois cases use a $70 pantry box (Groceries → Reduced 1%) and the $10 mug (6.25%); the Belgian cases use the $20 book (Books → Reduced 6%) and the $10 mug (21%).

| ID | Scenario | Steps | Expected | Actual | Note |
| --- | --- | --- | --- | --- | --- |
| D13 | Belgium: whole charge at the lowest rate in the box | Book + mug to Brussels, €10 shipping, BE rule "lowest rate" | €0.60 | €0.60 | Whole €10 at 6%; a value split would give €1.50 |
| D14 | Belgium: all goods at one rate | Mug + blender (both 21%) | €2.10 | €2.10 | Nothing to choose between |
| D15 | Illinois: majority of value is groceries | Pantry box + mug to Chicago, delivery terms not met | $0.10 | $0.10 | Groceries hold 87.5% of the value, so the whole $10 is at 1% |
| D16 | Illinois: no majority (50/50) | Pantry box + 7 mugs ($70 each side) | $0.36 | $0.36 | Falls back to a value split: $5 at 1% + $5 at 6.25%; the note says why |
| D17 | Illinois: delivery terms met | Tick "Conditions met" on the IL rule, same cart | $0.00 | $0.00 | The delivery-terms rule decides whether shipping is taxed at all; the majority test applies only when it is |
| D18 | ZIP-prefix shipping rule beats the state rule | NY ZIPs `100*` → exempt beside the NY state rule; ship to 10001, then Brooklyn 11201 | 0.00 / 0.12 | 0.00 / 0.12 | Manhattan exempt by the ZIP rule. Brooklyn follows the goods at the 4% state rate only, since the example NYC rate row is limited to the city "New York" |

The five shipping rules themselves (follows, exempt as stated, conditional, always taxed, delivery terms) are each covered by the acceptance page: NY, Arizona, California with conditions met and not met, Hawaii, Minnesota by weight, UK. The stress page covers the discount edge (a coupon that takes one item to $0, and a coupon that takes the whole basket to $0, where list prices are used).

## E. Order editing in admin

All 7 pass. Tax categories and the shipping split are resolved again at the order's own taxable address every time an admin clicks Recalculate, so an order edited after checkout stays consistent with what checkout would have charged.

| ID | Scenario | Steps | Expected | Actual | Note |
| --- | --- | --- | --- | --- | --- |
| E1 | Change the address, then Recalculate | Place the NYC order, change both addresses to Arizona, Recalculate | hoodie tax $2.52 | $2.52 | Arizona has no clothing rule, so the hoodie returns to Standard: $45 × 5.6% |
| E2 | Shipping tax after that change | Same order | $0.00 | $0.00 | Arizona exempts shipping |
| E3 | Line notes follow the new address | Read the hoodie's Tax rule note | No rule for this place → own class | same | The order screen explains why the line changed |
| E4 | Add an item in admin, then Recalculate | Back to NYC, add a $10 mug, Recalculate | $3.03 total tax | $3.03 | Book $1.78 + mug $0.89 + shipping $0.36 (30/75 of $10 at 8.875%) |
| E5 | Remove every taxable item | Only the exempt hoodie left, Recalculate | $0.00 | $0.00 | Shipping follows the only item and is exempt |
| E6 | Order flagged tax exempt | Mark the order tax exempt, add the book back, Recalculate | $0.00 | $0.00 | The exempt flag wins over everything |
| E7 | Refund the shipping line | Refund the full $10 shipping and its tax | $0.27 refunded tax | $0.27 | The refund carries the split tax, not a recomputed one |

The stress page also covers an order created from scratch in admin (no checkout session), which resolves at the order's address rather than the admin's, and Recalculate on an order placed before the feature was switched on.

## F and G. Store API and Analytics

All 6 pass. The block checkout sees the same totals as the classic cart, and the split shipping tax lands in Analytics attributed to the right rate rows, so Analytics → Taxes, the legacy Reports → Taxes and invoices all agree with the order.

| ID | Scenario | Steps | Expected | Actual |
| --- | --- | --- | --- | --- |
| F1 | Block cart totals match the classic cart | `GET /wc/store/v1/cart` for the reference cart | total tax 2.05 / shipping tax 0.27 | 2.05 / 0.27 |
| G1 | Analytics tax lookup rows | Complete the NYC order and sync it into `wc_order_tax_lookup` | NY State: order 0.80 / shipping 0.12 · NYC + MCTD: order 0.98 / shipping 0.15 | same |
| G2 | Zero-rated sales in the Taxes report | Look for a row for the hoodie's Zero rate class | no row | no row |
| G3 | Analytics → Taxes report (REST) | `GET /wc-analytics/reports/taxes` for today, NY State row | order 0.80 / shipping 0.12 | same |
| G4 | Analytics → Taxes summary stats | `GET /wc-analytics/reports/taxes/stats` for today | total tax 2.05 / shipping tax 0.27 | same |
| G5 | Legacy order tax rows | Read the order's tax line items | NY State 0.80/0.12 · NYC + MCTD 0.98/0.15 | same |

How to read G1: the $0.27 shipping tax is split across the two NY rate rows in proportion to their rates (4% → $0.12, 4.875% → $0.15), exactly as item tax is. No new column or table is needed; the feature only changes the amounts WooCommerce already records per rate. The Analytics → Taxes screen in the test store shows the two rows with those numbers and a $2.05 / $1.78 / $0.27 summary.

**G2 is the finding to decide on.** The Taxes report is keyed by rate row, and a zero-rated or exempt line has no rate row, so it never appears. That is stock behaviour today, but the feature makes far more lines zero-rated (every clothing sale under $110 in New York, every book in the UK), so a merchant loses sight of a number tax filings often ask for: how much was sold tax-free, and under which exemption. Options, in rough order of effort: (a) a "tax-free sales" total on the Taxes summary; (b) a row per tax category in the report, including zero-rated ones; (c) leave it to extensions. The prototype already stores the category and rule on each order line, so (a) and (b) have the data they need.

**Playground caveat.** WordPress Playground runs on SQLite, which has no `SUBSTRING_INDEX()`. Stock WooCommerce's Taxes per-rate table uses it, so that table is empty on Playground with or without this plugin (the summary and chart work). The blueprint now installs a small mu-plugin that adds the function; it is a test-store aid, not part of the proposal, and on MySQL it is not needed.

## H. Merchant setup mistakes

All 8 pass, in the sense that no mistake breaks checkout or charges the wrong tax. Each row says what the merchant did, what the store did about it, and whether the merchant was told. "Silent" means the store corrected or ignored the input without a message.

| ID | Mistake | What the store does | Told? | Actual |
| --- | --- | --- | --- | --- |
| H1 | Types country `us`, state `ny` | Uppercases on save; blank postcode becomes `*` | Silent (harmless) | US / NY / \* |
| H1b *second pass* | Types "United States (US)" and "New York" | Saved as `US` / `NY`. Country is now a picker; the state box lists that country's states and accepts a name | n/a | US / NY |
| H2 *second pass* | Saves a rule with no country | Row not saved; message: "Category rule 8 (Clothing) was not saved: pick a country, or 'Any country'." | Yes | not saved; message shown |
| H3 *second pass* | Types a price limit of "ten dollars" | Row not saved; message names the value and says to enter an amount like 110 or leave blank. The field also turns red as you type | Yes | not saved; message shown |
| H4 *second pass* | End date before start date | Row not saved; message: "it ends (2026-01-01) before it starts (2026-12-31)" | Yes | hoodie keeps Standard, $3.99 |
| H5 | Removes a tax category that rules point at | Category and its rules removed; products fall back to their own class. *Second pass:* ticking Remove shows "Also removes its 2 rules" and Save asks to confirm | Yes | both gone |
| H6 *second pass* | Adds a category named "Clothing" when one exists | Existing category and its provider code kept; message says so (the first run's harness never actually posted the new row) | Yes | kept, code kept, message shown |
| H7 | Rule points at a tax class that was deleted | Rule skipped; hoodie taxed at its own class in Texas. *Second pass:* the product edit preview now flags it too | Yes | $2.81 |
| H8 | Deletes a product category a product relied on | Product lands in Uncategorized with no tax category; taxed at its own class | Silent, by design | $4.44 |
| H9 *second pass* | Second rule for the same category, place and limit | Both saved; row 2 highlighted "Same as row 1, which wins. Remove one." plus a message on save | Yes | flagged |
| H10 *second pass* | Tiered rules in the "wrong" order: "any price → Reduced" above "under $110 → Zero" | Tighter limit wins regardless of order: $45 hoodie exempt, $150 coat at 2%. No duplicate warning, since different limits are a tier | n/a | 0.00 / 3.00 |

H3 was the one with a real cost in the first run: a merchant who meant "under $10" and typed it badly got a rule that exempted every price. It is now rejected. The one silent case left, H8, is a WooCommerce catalogue action outside this feature; the products list's Tax category column shows "Own tax class" so the product can be found.

## Merchant UX findings

From walking the five changed screens in the test store as a merchant setting the feature up for the first time, plus the silent behaviours in sections C and H. Each row is a confusion point with what was done about it in the second pass. Six of the eight actionable rows are fixed in the prototype; the Analytics one and the per-method conditions question are product decisions.

| # | Where | What confused | Status | What changed |
| --- | --- | --- | --- | --- |
| 1 | Category rules | Two rules for the same category and place both save; the first silently wins (C8) | Fixed | Duplicate rows (same category, place, limit, dates) are highlighted with "Same as row N, which wins" and a message on save (H9). Rows that differ only by price limit are a tier, not a duplicate, and the tighter limit now wins in either order (H10) |
| 2 | Category rules | A bad price limit is cleared rather than rejected, so the rule applies to every price (H3) | Fixed | The row is not saved and a message names the value; the field turns red while typing. Same for a blank country (H2) and an end date before the start (H4) |
| 3 | Analytics → Taxes | Zero-rated sales vanish from the report (G2), and this feature makes many more sales zero-rated | Open: decision | Options: a tax-free sales total on the Taxes summary, or a row per tax category. The order lines already carry the category and rule, so the data exists |
| 4 | Category and shipping rules | Country and state are free-text; `USA` or `New York` do not match | Fixed | Country is a picker ("Any country" plus the store's list); the state box lists that country's states and typed names are saved as codes (H1b). "Rate there" updates from the picker |
| 5 | Category rules | "Price per item under 110" has no currency and does not say it means the price charged | Fixed | Header reads "Price per item under (USD)" with a help tip (per-item price charged, sale price when on sale, before tax; blank = any); the cell shows the currency symbol |
| 6 | Tax categories table | Removing a category also removes its rules with no confirmation (H5) | Fixed | Ticking Remove shows "Also removes its N rules"; Save asks to confirm, naming the category and count |
| 7 | Products → Categories | Deleting a product category silently drops products to their own tax class (H8) | Left as is | Catalogue action outside this feature; the products list shows "Own tax class" |
| 8 | Rule tables and product edit | Rules whose tax class has no rates in their place are skipped; only "Rate there" said so (H7) | Fixed | The product edit preview now flags the rule: "no rates for this class there, so the rule is skipped and the product keeps its own tax class" |
| 9 | Shipping rules | "Conditions met" is a one-time, per-place answer; a merchant may expect it per shipping method | Open: decision | Moving it to the shipping method instance is about half a day; the requirements doc should say whether per-method is in scope |
| 10 | Shipping rules, mixed basket | Only value and weight were offered; Belgium and Illinois were documented limits | Fixed | Two more options, "Whole charge at the lowest rate in the box" and "Whole charge at the majority's rate (by value)", with a help tip naming the places; order notes explain which applied and why a fallback happened (D13–D17) |
| 11 | Shipping rules | No postcode column, unlike category rules | Fixed | Postcode / ZIP column with the same prefix matching (D18) |

What worked without comment: the per-screen banner, badges and tour made every new field findable; the live "Rate there" column caught every empty-class mistake during the walkthrough; the product edit preview showed the effective tax class by place before saving; and the order screen explained every line change after Recalculate (E3).

## Known limits

Of the prototype, unchanged by this run:

- Carrier conditions (California, South Carolina) are answered once per place, not per shipping method. Open decision (UX #9).
- Handling is not a separate charge. Decision for the requirements doc; the default is to leave handling to the fee's own tax class.
- The price limit is a cliff: at or over the limit the whole item is taxed. Excess-only limits (Massachusetts: tax only the part of each item above $175; Rhode Island at $250) are not modelled and would change how much of an item is taxed, not which class it gets. The biggest remaining gap; needs an in-or-out call before build.
- Automated cases drive the store with PHP and the REST API. `tools/checkout.spec.js` runs four reference carts through the block checkout with Playwright and reads the totals off the page; the NYC and Arizona carts were also walked through the block checkout by hand this pass (NY State $0.92 + NYC + MCTD $1.13; AZ $3.64 with no shipping tax). Playground is not reachable from this build environment, so the spec is for a local run.

Of the Playground environment:

- SQLite, not MySQL: see the Analytics caveat. Everything else in WooCommerce's tax code ran unmodified.
- Each page reload of a test script places new orders, so Analytics totals grow if the suite is run twice in one store. Boot a fresh Playground for clean numbers.
- The private-repo blueprint token lasts a few minutes; the launcher artifact embeds the blueprint instead, so it does not expire.

Sources: repo `george-vice/woo-core-tax-categories` at commit `03e4196`; `playground/wctc-test.php`, `playground/wctc-stress.php`, `playground/wctc-suite.php`; WooCommerce 11.1.2 on WordPress Playground, run on 2026-10-03.
