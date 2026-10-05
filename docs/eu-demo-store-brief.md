# Brief: an EU and Australia demo store for the prototype

The P2 post frames the feature for EU and UK stores on WooCommerce's own rate tables, but the Playground demo behind the launcher is a New York store in USD. This brief says what to build so the demo matches the post. It is a second store beside the current one, not a replacement: the 111-case suite stays on the New York store, which has the US state exceptions it needs.

## Deliverables

1. `playground/setup-eu.php`: builds the EU store (settings, rates, zone, categories, products, rules).
2. `playground/blueprint-eu.json`: built by `python3 tools/build-blueprint.py --store eu`. The script takes a `--store` flag (`us` default) that picks the setup file and the output name; everything else (plugin files, shim, test pages) is shared.
3. `playground/wctc-eu-check.php`: a short check page (the seven carts below) so the EU store is verified the same way as the US one.
4. `playground/launcher.html` and the hosted blueprint: the EU store becomes the default link; the US store stays reachable as "US store (state exceptions)".
5. README: a short "EU store" section mirroring the US one.

Do not change `setup.php`, the three existing test pages or `src/Examples.php` for this. If the EU example data belongs in `Examples`, add `Examples::load_eu()` and `add_rates_eu()` beside the existing methods rather than altering them; the "Load example data" buttons on the settings page can then offer both sets.

## Store settings

| Setting | Value | Why |
|---|---|---|
| Base address | Berlin, DE, 10115 | A German bookshop is the post's opening example |
| Currency | EUR | |
| Selling to | DE, FR, IT, IE, BE, NL, GB, AU | Enough to show class-switching, the Belgian mode and a GST market without a wall of rows |
| Prices entered | **exclusive** of VAT for the demo | Keeps the try-it arithmetic readable (€20 × 7%). Inclusive pricing is what EU stores use and is covered by suite case D5; mention that in the README rather than making the demo numbers awkward |
| Calculate tax based on | Customer shipping address | OSS: destination rates |
| Shipping | One zone "EU, UK and Australia" with all eight countries; Flat rate €4.90, taxable; Local pickup €0; Free shipping over €100 | The flat rate is the number in every example |
| Tax categories | On; shipping split by tax class off (it is implied by tax categories) | |
| Weight unit | kg | |

## Tax classes and rates

Use the three classes a store already has. Rate rows go in the Standard and Reduced rate tables; Zero rate has none, as in stock WooCommerce. Name every row `WCTC example …` so "Remove example data and rates" still finds them.

| Country | Standard | Reduced rate (the rate the country applies to books) |
|---|---|---|
| DE | 19% | 7% |
| FR | 20% | 5.5% |
| IT | 22% | 4% |
| IE | 23% | 9% |
| BE | 21% | 6% |
| NL | 21% | 9% |
| GB | 20% | (no row; books are zero-rated) |
| AU | 10% GST | (no row; GST has one rate, GST-free items use Zero rate) |

Shipping ticked on every row. Priority 1, not compound. These are illustrative, not advice; the post says so and the README should too.

## Tax categories and rules

Categories, with a provider code column filled for the Stripe Tax code where one is obvious:

| Slug | Name | Assigned to |
|---|---|---|
| `books` | Books | Product category "Books" |
| `childrens-clothing` | Children's clothing | Product category "Kids" (child of "Clothing") |
| `food` | Food | Product category "Pantry" |
| `digital-books` | Digital books | Set on the e-book product directly (shows the product-level override) |

Category rules (country, class). No price limits, no dates, no postcodes: EU rules are by product type.

| Category | DE | FR | IT | IE | BE | NL | GB | AU |
|---|---|---|---|---|---|---|---|---|
| Books | Reduced | Reduced | Reduced | Zero | Reduced | Reduced | Zero | (no rule: 10% GST) |
| Children's clothing | (no rule: Standard) | (no rule) | (no rule) | Zero | (no rule) | (no rule) | Zero | (no rule) |
| Food | Reduced | Reduced | Reduced | Zero | Reduced | Reduced | Zero | Zero (basic food is GST-free) |
| Digital books | Reduced | Reduced | Reduced | Reduced | Reduced | Reduced | Zero | (no rule) |

The gaps are the point: a children's jumper is Standard in Germany and Zero in Ireland with no change to the product, and a paperback that is reduced-rated across the EU carries full GST in Australia. One rule row with country `*` is worth adding as well, to show the wildcard: `Digital books, country *, → Reduced`, then the IE and GB rows override it. That is seven rows fewer and shows "most specific wins."

Shipping rules: **one row only**, `BE, Follows the goods, Whole charge at the lowest rate in the box`. Everywhere else, Australia included, has no row, which demonstrates the main claim: turn it on and shipping is right with nothing to configure. (Australia's GST treats delivery as part of the supply, so a mixed basket is apportioned, which is the default.) The guide banner on the Tax categories screen should say exactly that for this store.

## Products

Prices exclusive of VAT. Weights so a weight split could be shown if someone switches a rule.

| Product | Price | Product category | Tax category comes from | Weight |
|---|---|---|---|---|
| Paperback: *The Tin Drum* | €20 | Books | category | 0.4 kg |
| Children's jumper | €30 | Clothing → Kids | category (Kids) | 0.3 kg |
| Coffee beans 1 kg | €12 | Pantry | category | 1.0 kg |
| E-book: *The Tin Drum* | €10 | Books | product override → Digital books (virtual) | — |
| Mug | €10 | Kitchen | none: own class, Standard | 0.4 kg |
| Rain jacket | €60 | Clothing | none: own class, Standard | 0.8 kg |

The jacket exists so Clothing (adult) and Kids (child) sit side by side in the category tree with different tax categories, which is the inheritance story on Products → Categories.

## The seven carts the check page verifies

Flat rate €4.90 throughout. "Today" is what stock WooCommerce charges on shipping (all of it at Standard).

| # | Ship to | Basket | Item VAT | Shipping VAT (prototype) | Today |
|---|---|---|---|---|---|
| 1 | Berlin | Paperback + mug | 1.40 + 1.90 | **0.54** (⅔ at 7% + ⅓ at 19%) | 0.93 |
| 2 | Dublin | Jumper + paperback + mug | 0 + 0 + 2.30 | **0.19** (only the mug's sixth) | 1.13 |
| 3 | Paris | Paperback + jumper | 1.10 + 6.00 | **0.70** (0.11 + 0.59) | 0.98 |
| 4 | Brussels | Paperback + mug | 1.20 + 2.10 | **0.29** (whole €4.90 at 6%, the Belgian shortcut; a value split would give 0.54) | 1.03 |
| 5 | London | Paperback + mug | 0 + 2.00 | **0.33** (only the mug's third) | 0.98 |
| 6 | Amsterdam | E-book + mug | 0.90 + 2.10 | **1.03** (the e-book is virtual and doesn't ship, so the mug carries the whole €4.90 at NL Standard 21%; shipping exists because of the mug) | 1.03 |
| 7 | Sydney | Coffee beans + mug | 0 + 1.00 | **0.22** (GST only on the mug's share, 10/22 of €4.90) | 0.49 |

Add an eighth, non-cart check: with both settings off, cart 1 gives 0.93, to show opt-out parity on this store too.

Order screen walk-through for the post: place cart 2 (Dublin), open it, and the three lines read "Children's clothing · IE → Zero rate", "Books · IE → Zero rate", "Own tax class", with the shipping note showing the one-sixth split. Then change the address to Berlin and Recalculate: the jumper goes to 19%, the book to 7%, and the note changes with them.

## Copy that mentions the US store

Search the plugin for strings that assume the New York store and make them come from the data rather than the text:

- Settings → "Add example tax rates (NY, AZ, CA, HI, MN, IL, GB, BE)": label should be built from the example set that is loaded, or read "Add example tax rates" with the places in the help tip.
- Guide banner steps on the Tax categories screen mention Clothing, Groceries and Books; for the EU set the categories are Books, Children's clothing, Food and Digital books. Either make the banner read the category names from `Store::categories()` or keep it generic ("your tax categories").
- README "Try the acceptance examples" is US-specific by design; add an EU section rather than rewriting it.

## Playground specifics

- Playground runs on SQLite; the shim mu-plugin is already in the shared blueprint steps, nothing to do.
- `woocommerce_specific_allowed_countries` must list the eight countries or checkout will refuse the addresses.
- Blueprint `landingPage` should stay `/wp-admin/admin.php?page=wc-settings&tab=tax&section=tax_categories`.
- Host both blueprints on the same static host as `blueprint-eu.json` and `blueprint.json`; the launcher gets two buttons.

## Done when

- `wctc-eu-check.php` shows 8 of 8.
- The US suite still shows 25 / 16 / 70 on the US blueprint (nothing shared regressed).
- The launcher's default button boots the EU store and the post's try-it paragraph can be followed word for word.
