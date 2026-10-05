# Tax Categories for WooCommerce — Requirements Scope

Sep 30, 2026 · @George Jeng

Shipping tax is where the gap shows first. In most places a shipping charge is taxed like the goods it carries ([How Shipping Tax Works at Checkout](https://claude.ai/code/artifact/31b4c328-7db5-456b-90a7-0b052dad8925)), so a checkout has to know what each item is before it can tax the delivery. WooCommerce can't record what a product is for tax purposes, so it can't get shipping right on a mixed basket. The same gap stops it from applying each jurisdiction's own rules to the products themselves, such as exempt groceries in one state or tax-free clothing under $110 in New York. We propose adding opt-in tax categories to WooCommerce core. Merchants assign a tax category to their products, and rules they set for each jurisdiction use that category to pick the right tax class. Products are then taxed correctly where they're delivered, and shipping tax follows the items in the box.

**What we're proposing for core.** WooCommerce core has only tax classes today. We propose adding three things to core itself, not as a separate plugin: tax categories, category rules per jurisdiction, and a shipping tax split that follows the items. Each is opt-in.

**Tax class vs. tax category.** The two are easy to confuse, but they answer different questions. A tax class is a bucket of rates (Standard, Reduced rate, Zero rate), and a product carries the same class everywhere it ships. A tax category says what the product is (Clothing, Groceries, Books), and a rule for each place picks the class it gets there. Clothing shows the difference: it's zero-rated under $110 in New York but taxed at the Standard rate in California, and one class on a hoodie can't say both. Doing it with classes alone takes a class for every category-and-treatment combination, plus duplicated rate rows for each place. Categories don't replace classes: they pick between the classes and rates a store already has.

## Problem

Four problems at checkout come from that one gap. The most visible is [WOOPLUG-45](https://linear.app/a8c/issue/WOOPLUG-45/woocommerce-calculates-shipping-tax-wrong-for-mixed-taxable-and), open since 2019.

| Problem | What goes wrong | Technical cause |
| --- | --- | --- |
| No tax categorization of products | Merchants hand-assign a class to every product; a rule like "groceries exempt in one state" needs duplicated rate rows or a paid provider | Tax class is a free-text field on products and variations only; product categories have no tax field; rate rows key only on class and location |
| Shipping tax on mixed baskets | One standard-rated item makes all shipping standard-rated, overcharging VAT and sales tax | `WC_Tax::get_shipping_tax_class_from_cart_items()` returns a single class (Standard wins) |
| Shipping rules differ by jurisdiction | Shipping follows the goods in 23 US states, the EU, the UK and Australia; 13 states exempt separately stated shipping, 7 exempt it only under conditions, HI and NM always tax it, and SC depends on delivery terms. Split methods vary too | One store-wide shipping tax class setting; the only per-place control is each rate row's Shipping checkbox, which can exempt shipping but can't split it or check conditions |
| Per-item shipping split is lost | Per-item shipping loses its item-by-item tax when an order is edited | Core Flat rate charges one amount per order, and admin Recalculate re-taxes shipping with one class |

## Solution

Three additions to core, all off until a merchant turns them on.

How each admin screen changes: see [Admin mockups](#mjtgh53886g.57215) below.

### 1. Tax categories

- An optional label for what a product is for tax (Groceries, Clothing, Digital books), set on product categories, products or variations.
- Ships with a starter list aligned to the US Streamlined Sales Tax definitions and the EU's reduced-rate categories; merchants can add their own.
- A line resolves its category from the most specific level: variation, then product, then product category (walking up parents). If none is set, the product's own tax class applies, exactly as today.
- Each tax category can store the matching code for a tax provider (for example, a Stripe Tax product tax code), so a provider can use the same categorization instead of a second mapping.

### 2. Category rules pick the tax class

- A rule maps a tax category in a jurisdiction to one of the store's existing tax classes, for example Groceries in NY → Zero rate.
- Rules can apply everywhere with exceptions, carry a per-item price threshold ("clothing exempt under a set amount"), and have start and end dates.
- A category never holds a rate. The existing rate table is unchanged and still supplies every rate.

&#91;embedded content: how a line item gets its tax class · 2 questions\]

A line's tax changes only when it has a category and there's a rule for the ship-to place; everything else falls through to today's behavior.

### 3. Shipping tax follows the items

- Shipping on a mixed basket is split across the items' tax classes by value, instead of taking one class. This fixes [WOOPLUG-45](https://linear.app/a8c/issue/WOOPLUG-45/woocommerce-calculates-shipping-tax-wrong-for-mixed-taxable-and).
- Each jurisdiction gets a shipping rule, one of the five rules shipping tax follows: follows the goods, exempt if shown separately, exempt only under conditions, always taxed, or depends on delivery terms. The rule also sets how a mixed basket is split: by value by default, or by weight where the state allows it. The rules by jurisdiction are explained in How Shipping Tax Works at Checkout.
- Checkout applies them in the same order as the explainer's decision flow: the place's rule first (exempt means no shipping tax; always taxed means the full charge at the Standard class, even when every item is exempt). Then per-item charges take their item's class, a basket with one class gives shipping that class, and only a mixed basket is split.
- Some places exempt shipping only when the merchant's delivery meets a condition. California, for example, exempts it only when the order goes by common carrier or USPS and the merchant charges no more than the actual cost. South Carolina instead depends on who delivers and when ownership passes to the buyer. WooCommerce doesn't record these facts on an order, so the shipping rule for those places asks the merchant to answer them once (for example, "I ship by common carrier and charge no more than my actual cost"). Checkout then applies that answer to every order going there.
- Places without a shipping rule keep using today's Shipping tax class setting.
- Per-item shipping charges take their item's tax class, and admin Recalculate keeps the split.
- Orders, emails and invoices can show shipping tax per class, which states that fully tax an undivided charge (NY, NJ, WI, PA, KY, SC) require.

### 4. Opt-in, no migration

- A "Use tax categories" setting under WooCommerce → Settings → Tax turns categories on; the shipping split is its own separate option. Both are off after upgrade.
- Nothing is converted. The upgrade adds new, empty storage; no tax class, rate row or product setting is rewritten.
- With both off, totals match the previous version exactly, and existing tax functions, filters and REST fields behave as before, so WooCommerce Tax, Stripe Tax and Avalara keep working.
- Turning a setting off returns every line to its product's own tax class.

## Admin mockups

Seven admin screens change. Each mockup is the live 3D Widgets test store with the proposed fields added in the browser; nothing was saved to the store. New or changed elements are outlined in purple and marked **New** or **Changed**. Category names and amounts are example data, and the examples match the acceptance examples below.

### 1. Settings → Tax → Tax options

Two changes: a **Tax categories** setting (off by default), and a new **Split across cart items by value** choice for the shipping tax class, used wherever a place has no shipping rule of its own. A **Tax categories** link joins the section menu.

**Today**

&#91;image: Tax options today\]

**Proposed**

&#91;image: Tax options with the tax categories setting and shipping split\]

### 2. Settings → Tax → Tax categories (new section)

One new page with three tables: the store's tax categories (each with an optional tax provider code), the category rules that pick a tax class for each place, and the shipping rules for each place. The Clothing rule sends clothing under $110 shipped to New York to Zero rate. The shipping rules table doesn't yet show the conditions a merchant answers for places like California, and its Handling column depends on the handling open question below.

&#91;image: Tax categories and category rules\]

&#91;image: Shipping rules by place\]

#### Where shipping rules live: three options

The same five places shown three ways. Decision still open.

**Option 1: folded into the rate table.** Two columns replace the Shipping checkbox. Everything is in one table and CSV imports carry it, but the rule repeats on every city and ZIP row and on each class's rate table. The table also has no room for California's conditions.

&#91;image: Option 1: shipping rule columns in the rate table\]

**Option 2: a separate shipping rules table (current proposal).** The rate table is untouched, and each place's rule is set once on the Tax categories page, where California's conditions fit. Merchants manage rates and shipping on two screens.

&#91;image: Option 2: unchanged rate table plus a separate shipping rules table\]

**Option 3: stored separately, shown together.** Each place's rule is stored once and appears as a header row above that place's rates, on every class's rate table. It's one screen to manage with no repeated data, and the rate table's columns and CSV format stay as they are.

&#91;image: Option 3: shipping rule as a header above each place's rates\]

### 3. Products → Categories

A **Tax category** field on the add and edit forms, and a column in the list. Child categories show the category they inherit, such as Hoodies inheriting Clothing.

&#91;image: Product categories list with a tax category column\]

&#91;image: Add category form with a tax category field\]

### 4. Products → All Products

A **Tax category** column showing where each product's category comes from (inherited or set on the product), and a filter to find products by tax category or with none.

&#91;image: Products list with a tax category column and filter\]

### 5. Product edit → General

A **Tax category** field sits under the existing tax class, which stays unchanged. A preview shows the effective tax class by place, so merchants can see what a rule will do before an order comes in: here, Zero rate in New York under the Clothing rule, and the product's own Standard class everywhere else.

&#91;image: Product edit screen with a tax category field and effective tax class preview\]

### 6. Product edit → Variations

Each variation gets a **Tax category** that defaults to "Same as parent," overridden only when a variation is taxed differently (for example, a digital version).

&#91;image: Variation panel with a tax category field\]

### 7. Order edit

Each line shows its tax category and the rule that applied, and the shipping line shows how its tax was split by value. This is the New York City acceptance example: the hoodie at Zero rate, the book at Standard, $0.27 of shipping tax and $2.05 of tax in total.

&#91;image: Order edit with tax category per line and shipping tax split\]

## Acceptance examples

- A store that upgrades without turning anything on gets identical totals.
- UK basket: £40 zero-rated book + £10 standard-rated mug, £5 shipping → shipping VAT on £1.00 only (£0.20).
- A Groceries category plus the rule "NY → Zero rate" charges 0% on groceries shipped to NY and the normal class elsewhere, with no new rate rows.
- Arizona order: shipping shown on its own line isn't taxed, whatever the basket holds.
- New York City basket: $45 hoodie (Clothing) + $20 book, $10 shipping. The rule "Clothing in NY under $110 → Zero rate" exempts the hoodie; shipping is split by value, so only the book's $3.08 share is taxed at 8.875% → shipping tax $0.27, total tax $2.05. Taxing all $10 of shipping would charge $0.89.
- Minnesota basket set to split by weight: $60 jacket (2 lb, exempt) + $40 blender (6 lb), $16 shipping → $12.00 of shipping taxed at 6.875% ($0.83).
- Hawaii order of exempt items only: shipping is still taxed in full.

## Out of scope

- Standalone tax bugs that can be fixed on their own: fee tax with inclusive prices ([WOOPLUG-1218](https://linear.app/a8c/issue/WOOPLUG-1218/adding-fees-do-no-respect-the-prices-entered-with-tax-settings)), rounding ([WOOPLUG-3368](https://linear.app/a8c/issue/WOOPLUG-3368/discrepancies-in-the-order-total-and-checkout-total-probably-related), [WOOPLUG-4677](https://linear.app/a8c/issue/WOOPLUG-4677/store-api-cart-totals-incorrect-due-to-tax-rounding), [WOOPLUG-2130](https://linear.app/a8c/issue/WOOPLUG-2130/the-number-precision-is-not-accurate-when-the-last-decimal-digit-is-0)), Product Add-Ons ([WOOTAX-40](https://linear.app/a8c/issue/WOOTAX-40/taxes-bug-in-total-when-using-product-add-ons-extension)), rate IDs ([WOOPLUG-757](https://linear.app/a8c/issue/WOOPLUG-757/recycled-tax-rate-ids-returns-invalid-data-when-filtering-orders)), localization ([WOOPLUG-3961](https://linear.app/a8c/issue/WOOPLUG-3961/enhancement-better-support-for-non-latin-tax-class-names), [WOOPLUG-1443](https://linear.app/a8c/issue/WOOPLUG-1443/the-tax-state-code-for-the-indian-state-of-chhattisgarh-is-incorrect)) and structured data ([WOOSHIP-828](https://linear.app/a8c/issue/WOOSHIP-828/request-opengraph-and-schema-tax-settings-separate-form-tax)).
- Filing, remittance and registration thresholds, which stay with tax providers.
- Illinois's majority test and Belgium's lowest-rate shortcut. Both places also accept a split by value, so v1 ships value and weight only.
- Customs duties, such as the EU's flat €3 duty on low-value parcels from July 2026. They aren't VAT or sales tax.

## Open questions

- [ ] Should new stores get tax categories on by default, while existing stores stay opted out?
- [ ] A product in several product categories with different tax categories: resolve by category priority, or by the product's primary category?
- [ ] Shipping rules for all 50 states, DC, the EU, the UK and Australia are researched in the explainer. Should WooCommerce ship them pre-filled (high-confidence entries only), and which jurisdictions get pre-filled category rules?
- [ ] Handling: several states tax handling differently from delivery (CA, MD, NV, VA), and the shipping-rules mockup has a Handling column, but core has no separate handling charge. Add a handling line in v1, or drop the column?
