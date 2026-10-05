# Tax Categories Prototype: Stress Test Findings

Oct 2, 2026 · @George Jeng

> **Update, October 3:** all eight problems below are fixed in the prototype. Rerun in Playground: acceptance checks 25 of 25, edge cases 16 of 16. Carrier conditions per shipping method, handling, Illinois's majority test and Belgium's lowest-rate shortcut are still open.

## Summary

The [prototype plugin](https://github.com/george-vice/woo-core-tax-categories) was run on a real WooCommerce install in WordPress Playground. It passes all **24 acceptance checks**: NYC, UK, Arizona, Hawaii, California, Minnesota, the $110 limit, feature off = stock WooCommerce, and an order that keeps its shipping split after Recalculate. Block checkout gives the same answers.

It falls apart on edge cases. **8 of 15** came out wrong, and in each one the store charges the wrong tax without any warning:

- Shipping is split on list prices, so a coupon doesn't change the split.
- Per-item shipping charges are split by value instead of item by item.
- Places with no shipping rule fall back to today's single-class behavior, so every place needs a rule.
- The plugin finds the place from the shipping address only. It ignores "tax based on billing" and local pickup.
- Orders created in admin resolve rules against the admin's own session, not the order's address.
- A rule that points at a tax class with no rates in that place quietly charges 0%.
- A product in two product categories gets whichever comes first.

One more problem showed up outside the scenarios: customers see the internal "Tax category" and "Tax rule" notes on their order confirmation.

The merchant setup has three problems: too many overlapping shipping switches, typed codes instead of pickers, and no defaults. The biggest streamlining wins are below. Turning tax categories on should make shipping follow the goods everywhere, with rules only for exceptions. A one-screen setup should map product categories to tax categories. Carrier conditions belong on the shipping method, not on each state. Ready-made shipping rules can come from the explainer's research. A basket tester would show merchants what a rule will do.

## Results

Test store: New York base, NYC rate 8.875% (4% state + 4.875% city), $10 flat-rate shipping unless noted, and the example rules from the scope doc. "Should be" is what the rules in [How Shipping Tax Works at Checkout](https://claude.ai/code/artifact/31b4c328-7db5-456b-90a7-0b052dad8925) call for.

**Acceptance checks: 24 of 24 pass.** These cover feature off (identical to stock), NYC hoodie + book ($0.27 shipping, $2.05 total), the $150 coat over the $110 limit, the UK book + mug, Arizona, Hawaii, California with conditions met and not met, Minnesota by weight, and a real order through checkout that keeps its split after Recalculate.

**Edge cases: 7 of 15 right.**

| Area | Scenario | Should be | Plugin gives |  |
| --- | --- | --- | --- | --- |
| Discounts | NYC hoodie + book, $20 coupon on the book only (book now $0) | $0.00 shipping tax | $0.27 | Wrong |
| Per-item shipping | NYC hoodie + book, $8 per item ($16) | $0.71 | $0.44 | Wrong |
| Multiple categories | NYC $45 product in both Books and Clothing | Merchant's choice | Books won (taxed) | Wrong |
| Place without a rule | Texas: zero-rated formula $30 + mug $10, no TX shipping rule | $0.16 | $0.63 | Wrong |
| Tax based on billing | Billed to NYC, shipped to Arizona | $0.27 | $0.00 | Wrong |
| Local pickup | Arizona customer picks up at the NY store, $5 fee | $0.14 | $0.00 | Wrong |
| Setup mistake | Rule sends Clothing in TX to Reduced rate, which has no TX rates | Warning; Standard $2.81 | $0.00, silently | Wrong |
| Admin-created order | Admin adds a hoodie to a manual NYC order (admin's session is in CA) | $0.00 | $3.99 | Wrong |
| Price limit | NYC 3 hoodies at $45 ($135 line) | $0.00 | $0.00 | OK |
| Virtual items | UK e-book (virtual) + mug | $2.00 | $2.00 | OK |
| Variations | UK Cookbook eBook variation overriding Books → Digital books | $0.00 | $0.00 | OK |
| Tax-exempt customer | Hawaii order for an exempt customer | $0.00 | $0.00 | OK |
| Place without a rule | Same Texas order with the split setting on | $0.16 | $0.16 | OK |
| Free shipping | $0 shipping | $0.00 | $0.00 | OK |
| Discounts | Coupon makes both items $0, shipping still $10 | $0.27 | $0.27 | OK |

## Where it falls apart

Ordered by how likely a merchant is to hit it. Each one charges the wrong tax silently, unless noted.

### 1. Places without a shipping rule fall back to today's behavior

**What happens.** With tax categories on, a place with no shipping rule gets stock WooCommerce: one class for all shipping, Standard wins. Texas follows the goods, but the mixed basket was charged $0.63 instead of $0.16. Every US state, EU country and other market a store sells to needs its own rule to be right. The separate "Shipping tax split" setting fixes this, but it's off by default and easy to miss.

**Fix.** When tax categories are on, make "follows the goods, split by value" the default everywhere. Shipping rules then only record exceptions: exempt-if-separate states, conditional states, HI and NM. Drop the separate split setting.

### 2. The plugin takes the place from the shipping address only

**What happens.** For a store that taxes by billing address, items were taxed for New York (billing) but shipping used Arizona's rule (exempt): $0 instead of $0.27. Local pickup has the same problem. WooCommerce taxes pickup at the store's address, but the plugin used the customer's address and got $0 instead of $0.14.

**Fix.** Use the same taxable location WooCommerce uses for items (`get_taxable_address()`, which already handles billing, the store base address and pickup), not the package destination.

### 3. Coupons don't change the split

**What happens.** A $20 coupon on the book left it at $0, so only the exempt hoodie had value. Shipping should have been exempt, but the split fell back to list prices and charged $0.27.

**Fix.** Split on discounted line totals. Fall back to list prices only when the whole basket is $0 (that case worked).

### 4. Per-item shipping is split by value, not item by item

**What happens.** With $8 per item, the explainer says the book's $8 is taxed and the hoodie's $8 isn't, giving $0.71. The plugin split the $16 by value and gave $0.44. Flat rate with `[qty]` or shipping-class costs, Table Rate and per-product shipping all hit this.

**Fix.** Core shipping methods pass a cost per item. WooCommerce already supports this through `get_taxes_per_item`, but core Flat rate doesn't use it. Each item's cost would then be taxed by its own class.

### 5. Admin-created orders use the admin's location

**What happens.** When an admin adds a product to a manual order, the category rule is resolved against the admin's own session address (California), not the order's address (New York). The NY hoodie was taxed $3.99 instead of $0.

**Fix.** Resolve tax categories against the order's address when items are added in admin and on Recalculate. Store the resolved class on the line item, as checkout does.

### 6. A rule can point at a class with no rates and charge 0% silently

**What happens.** A rule sending Clothing in Texas to Reduced rate made clothing tax-free in Texas, because Reduced rate has no Texas rate rows. The merchant meant "lower", not "zero", and nothing warned them.

**Fix.** Check rules when they're saved. Warn when the target class has no rates for that place, and show each rule's effective rate next to it (for example "Reduced rate in TX: no rates, 0%").

### 7. Products in several product categories get whichever comes first

**What happens.** A $45 product in both Books and Clothing took Books and was taxed, but swapping the category order would exempt it. The result depends on term order, not a merchant decision.

**Fix.** Pick a clear rule and show conflicts. Either use the product's primary category, or ask the merchant to set the tax category on the product when its categories disagree. Flag conflicting products in the products list.

### 8. Customers see the internal tax notes (not in the edge-case table)

**What happens.** "Tax category: Clothing (product category: Clothing)" and "Tax rule: US NY, under 110 → Zero rate" appear on the customer's order confirmation, because they're stored as visible line item meta. They'd also show in emails and My Account.

**Fix.** Store them as hidden meta and show them only on the admin order screen.

### Rules the prototype doesn't model yet

- **Carrier conditions are per place, not per shipping method.** California's exemption depends on how each order ships: common carrier at cost is exempt, the merchant's own van isn't. A store offering both UPS and local delivery can only tick one "conditions met" box for California. South Carolina has the same problem.
- **Illinois's majority test and Belgium's lowest-rate shortcut** aren't implemented. Value splits are accepted in both, so this isn't a wrong answer, just a missed option.
- **Handling** isn't separable from delivery. States that tax handling differently (CA, MD, NV, VA) can't be modeled.

## Merchant setup review

This walks the prototype as a merchant setting it up from scratch: a New York store selling clothing and books to the US and UK.

### What setup takes today

1. Tax options: tick **Tax categories**. Decide whether to tick **Shipping tax split**, and how it relates to the existing **Shipping tax class** setting.
2. Tax categories page: create categories and type provider codes by hand.
3. Write category rules, typing country and state codes (`US`, `NY`), a price limit, and a tax class.
4. Write a shipping rule for every place sold to, again with typed codes.
5. Go to Products → Categories and edit each product category, one at a time, to set its tax category.
6. Check the rate table has rows for every class a rule points at.
7. Place a test order to see whether it worked.

### Where it's hard

- **Four controls decide how shipping is taxed:** the Shipping checkbox on each rate row, the Shipping tax class setting, the Shipping tax split setting, and the shipping rules. The order they apply in is invisible. In Arizona, for example, a shipping rule overrides an unticked Shipping checkbox.
- **No defaults.** Nothing is right until every place has a shipping rule (finding 1). A US seller would need 51 rows before shipping tax is correct everywhere.
- **Typed codes, no validation.** A typo like `N.Y.` or `NewYork` is saved and never matches, so the rule quietly does nothing. Tax classes are picked from a list, but nothing shows whether that class has rates where the rule applies (finding 6).
- **Category assignment is slow.** It's one product category at a time on the full edit screen, with no bulk or quick edit, no CSV column, and no view of which products are still uncategorized.
- **Carrier conditions are in the wrong place.** "Conditions met" is a tick per state, but it describes how the store ships, which differs per shipping method.
- **No way to check the result before customers see it.** The product preview shows the class by place, but there's no view of a whole basket, the shipping split, or which rule fired.

### Ways to streamline

Roughly in order of impact.

1. **One switch, sensible defaults.** Turning on tax categories also makes shipping follow the goods, split by value, everywhere. Shipping rules become exceptions only. Retire the separate split setting, and once a store opts in, show the Shipping checkbox and Shipping tax class as read-only. This closes finding 1 and removes most of the shipping confusion.
2. **Ready-made shipping rules.** Ship the researched rules for the 50 states, DC, the EU, the UK and Australia as a preset the merchant reviews and turns on, each with a "last verified" date. A US seller goes from 51 typed rows to one click.
3. **One-screen category mapping.** After opting in, show every product category with a tax category dropdown pre-filled by name (Clothing → Clothing, Books → Books). Show the number of products each covers, and list conflicts and uncategorized products. That replaces step 5's page-by-page editing.
4. **Carrier details on shipping methods.** Each shipping method records "Delivered by: common carrier or USPS / our own vehicle / the customer's own carrier" and "Charged at cost". California and South Carolina then resolve automatically per order, with no per-state checkboxes.
5. **Pickers instead of typing.** Use WooCommerce's existing country and state dropdowns, a searchable list of standard category codes (Streamlined Sales Tax and Stripe tax codes), and next to each rule the rate it gives in that place.
6. **A basket tester.** In settings, pick products and an address and see each line's tax, the shipping split, and which rule fired. The prototype's test page already does this; it's a small step to put it in the UI.
7. **A health check.** One panel listing products with no category, products whose categories conflict, rules pointing at classes with no rates, and rules that never match because of a typo.
8. **Bulk tools.** Add a Tax category column to product CSV import and export, a quick edit field, and import and export for rules.

Items 1, 2 and 4 also simplify the proposal: fewer settings, and the explainer's research becomes product data instead of merchant homework.

## Not covered yet, and how to rerun

Not tested here:

- prices entered including tax
- multiple shipping packages and marketplace (multi-vendor) setups
- Subscriptions renewals
- refunds of split shipping tax
- compound rates
- cross-border EU orders under OSS (the EU One-Stop Shop for VAT on cross-border sales)
- performance on large catalogs

To rerun everything, boot `playground/blueprint.json` from the repo in WordPress Playground (the README explains how while the repo is private). Then open:

- `/wctc-test.php` for the 24 acceptance checks
- `/wctc-stress.php` for the 15 edge cases above

Both pages build real carts and orders with WooCommerce, so they double as regression tests once the fixes land.
