# Handoff: tax categories prototype

Written 2026-10-03 at commit `03e4196` (plus this handoff) so a fresh Claude Code session in this repo can carry on without the chat history. Read this, then `CLAUDE.md`, then `docs/`.

## Who and why

George Jeng, Senior PM at Automattic (WooCommerce), is proposing an opt-in **tax categories** feature for WooCommerce core: a label for what a product is for tax (Clothing, Groceries, Books), per-place **category rules** that map a category to one of the store's existing tax classes, and **shipping tax that follows the items** instead of taking one class for the whole charge. This repo is the working prototype, packaged as a plugin only so it can be installed and removed on a test store. The proposal itself is for core.

Working preferences George has stated: state what information is needed and flag assumptions before answering; hyperlink Linear ticket numbers to their Linear URL; explain finance terms when they come up.

## Where things are

| What | Where |
|---|---|
| Plugin source | `src/` (see `CLAUDE.md` for the layout) |
| Pure logic tests | `php tests/EngineTest.php` (48 pass) |
| Playground test pages | `playground/wctc-test.php` (25), `wctc-stress.php` (16), `wctc-suite.php` (70, `?format=json`) |
| Playground blueprint | `playground/blueprint.json` (EU-sourced, built from `setup-eu.php`), rebuilt by `python3 tools/build-blueprint.py`. Also `playground/blueprint-us.json` (dev-only, built from `setup.php`, holds the state-exception data and runs the full 111-case suite); rebuilt via `--store us` or `--store all`. |
| One-click launcher page | `playground/launcher.html` (same content as the claude.ai artifact "Tax categories prototype"); it embeds the blueprint in the Playground URL fragment, ~365 KB |
| Block-checkout run | `tools/checkout.spec.js` (Playwright; needs a machine that can reach playground.wordpress.net) |
| Requirements scope | `docs/requirements-scope.md` (exported from the Claude doc; images are placeholders there) |
| First stress-test findings | `docs/stress-test-findings.md` (all eight problems since fixed) |
| Test cases and results | `docs/test-cases.md` (111/111 pass, UX findings, known limits) |
| Shipping explainer | "How Shipping Tax Works at Checkout", a Claude doc and a P2 post; not in this repo. Its five rules are `Engine::modes()` |

Claude docs (claude.ai): requirements `f8088762-47b6-480e-9e64-be19c2f0f685`, findings `e9856c73-7060-4e1b-b545-ff0b58d734fe`, test cases `de6ff1d5-da5d-44e7-9880-ce09f894294d`, explainer `31b4c328-7db5-456b-90a7-0b052dad8925`. Linear: [WOOPLUG-45](https://linear.app/a8c/issue/WOOPLUG-45/woocommerce-calculates-shipping-tax-wrong-for-mixed-taxable-and) is the bug the shipping split fixes.

## State of play

Everything in the requirements doc is built and passing, plus the second-pass work from 2026-10-03:

- **Excess-only price limits** (MA $175, RI $250) via a new `limit_mode` per rule; see decision 1 below for the detail.
- **Tax-free sales report** under WooCommerce → Tax-free sales, filling the Analytics → Taxes gap for zero-rated and partially-exempt lines. See decision 2 below.
- Mixed-basket modes beyond value and weight: **lowest rate in the box** (Belgium) and **majority of value** (Illinois, falls back to a value split at 50/50). `Engine::splits()`.
- Postcode/ZIP column on shipping rules, same prefix matching as category rules.
- Rule validation on save with messages (blank country, non-numeric price limit, end before start, duplicate rows); tiered rules resolve to the tighter price limit regardless of row order (`Engine::match_category_rule`, `Engine::duplicate_category_rules`).
- Country picker and state datalist; typed names ("New York", "United States (US)") saved as codes (`Settings::place()`).
- Currency and help tip on the price limit; confirmation before removing a category with rules; re-adding an existing category name keeps it; product preview flags rules skipped for lack of rates.
- Example data includes Illinois and Belgium rules and rates.
- Playground runs on SQLite; `playground/sqlite-shim.php` (an mu-plugin the blueprint installs) adds `SUBSTRING_INDEX()` so stock WooCommerce's Analytics → Taxes per-rate table renders. Not part of the proposal.

## Open decisions (for the requirements doc, not code)

1. ~~**Excess-only price limits.**~~ **Done (2026-10-03).** Each category rule now has a `limit_mode` field (`cliff` default, matching NY behaviour; or `excess` for MA $175 / RI $250). `Engine::apply_rule($rule, $price)` returns `{tax_class, taxable_fraction}`; Resolver scales the line's tax via `woocommerce_calc_tax` (two-shot counter: subtotal + total), and Shipping dual-emits items with partial excess (taxable portion to the rule's class, exempt portion to `__exempt`) so shipping-follows-goods stays correct. Admin UI has a Mode column with validation ("Excess mode needs a price limit"); example data includes MA and RI rules + rate rows. New suite section I covers it.
2. ~~**Tax-free sales in Analytics.**~~ **Done (2026-10-03).** New admin page WooCommerce → Tax-free sales (`src/Admin/TaxFreeReport.php`). Date range + three summary cards (tax-free sales, taxable category sales, tax collected) + a per-category table. Reads order items by `_wctc_tax_category` meta from Completed/Processing/On hold orders. For excess-only rules, counts the exempt portion per unit as tax-free. Linked from the Tax categories settings page. Suite section J covers it. The real core proposal would land these numbers inside Analytics → Taxes itself (a React slot change), not as a sibling page.
3. ~~**Carrier conditions per shipping method.**~~ **Documented limitation (2026-10-05).** "Conditions met" (California, South Carolina) stays as a per-place answer in V1. The right home for a future core version is probably the shipping method instance with a zone-level default (half a day of work); recorded here so the requirements doc flags it rather than hiding it. Mitigation for merchants with mixed carriers: leave the CA/SC rule off entirely — shipping then follows the goods for that state, which over-taxes some orders but never under-taxes.
4. **Handling as a separate charge.** Default is to leave handling to the fee's own tax class.
5. **Where shipping rules live** (three options in the requirements doc; option 2, a separate table, is what the prototype does).

## How to run it

- Boot: open `playground/launcher.html` in a browser, or go straight to `https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fbright-willow.view.fast%2Fblueprint.json`. The launcher is now a 5 KB page that redirects to that Playground URL; the blueprint lives on Spacefast at `https://bright-willow.view.fast/blueprint.json` (public, `Access-Control-Allow-Origin: *` via a `_headers` file).
- Spacefast Space: `spc_68ea168bf2234c7e85b73efdccd68850` ("Woo Tax Categories" / slug `bright-willow`), claimed by George's team. `.spacefast/space.json` keeps the id so subsequent publishes update this Space in place. To republish after a plugin change: `python3 tools/build-blueprint.py --store all`, then post `blueprint.json` + `blueprint-us.json` + `_headers` + `_redirects` to `/v1/publish` with the saved bearer and `spaceId`. `_redirects` maps the legacy `/blueprint-eu.json` to `/blueprint.json` with a 302 so old bookmarks still work.
- US dev blueprint: `https://bright-willow.view.fast/blueprint-us.json` boots the New-York store (state exceptions, excess-only limits) and runs the 111-case suite. Not shown in the README; use it when iterating on US-only behaviour.
- In the booted store, open `/wctc-test.php`, then `/wctc-stress.php`, then `/wctc-suite.php` (the suite reuses the stress page's products).
- After editing plugin files: lint (`for f in $(find . -name '*.php'); do php -l "$f"; done`), `php tests/EngineTest.php`, `python3 tools/build-blueprint.py`, commit, then regenerate `playground/launcher.html` by replacing the JSON inside `<script type="application/json" id="blueprint">` and the "Prototype version <sha>" line.
- Browser automation note: Playground renders WordPress in a nested iframe; `window.playground.run({code})` on the outer page runs PHP in the store, which is the fastest way to inspect state.

## Conventions

- Opt-in: with both settings off, totals must match stock WooCommerce exactly (suite section A proves it).
- Categories never hold rates; rules point at existing tax classes.
- All data in new keys (`wctc_*` options, `wctc_tax_category` term meta, `_wctc_tax_category` / `_wctc_tax_rule` / `_wctc_shipping_tax` order item meta, hidden from customers).
- Keep `Engine.php` free of WordPress calls so `tests/EngineTest.php` runs with plain `php`; add a case for any new rule.
- Commits so far end with a `Co-Authored-By: Claude …` trailer.
