# Changelog — Shipping Table Rates

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [1.2.0] — 2026-06-04 — Stripe portal licensing + v1.1.1 bug fixes

### Added

- **Stripe-portal subscription licensing.** Adds the SP-XXXX subscription-key flow to STR — the same pattern shipped with `ETechFlow_BackorderEtaDisplay` v1.3.0 and `ETechFlow_NextDayEligibility` v1.8.0. Three billing-period plans (Weekly $9/wk, Monthly $29/mo, Yearly $290/yr — same shape as `ETechFlow_DeliveryDate`) with in-admin Stripe Checkout, automatic key activation, portal-validated server-IP enforcement, and 48-hour offline grace when the portal is unreachable. HMAC per-module keys and bundle keys (`LICENSING_PROTOCOL.md`) keep working unchanged for offline activation.
- **License gate page** under **eTechFlow → Shipping Table Rates → License & Plans**. Dark plan-cards UI with "Select Plan & Pay" + "Enter License Key" CTAs. On payment success, the SP-XXXX key is saved to `etechflow_shippingtablerates/license/license_key` automatically.
- **Payment settings group** under Stores → Configuration → eTechFlow → Shipping Table Rates → Payment (Stripe). Accepts Stripe `sk_test`/`sk_live` (Encrypted) + `pk_test`/`pk_live` + currency.
- **Bundle License Key field** in the License group — paste a shared eTechFlow bundle key once to activate every installed eTechFlow module.
- **IP-block auto-management.** When the portal returns `ip_blocked:true`, the licence key is auto-cleared and the `ip_blocked` flag is set; when the IP is re-permitted, the next portal round-trip restores the key from `issued_key` so the module unlocks without admin intervention. A *manual* key clear (without the flag) keeps the module locked, distinguishing the two cases.

### Fixed

- **`Block/Adminhtml/Method/Edit.php`** — was missing the `Registry` injection, causing the admin Method Edit page to fail with `Undefined property: $_coreRegistry`. Added explicit `__construct(Context, Registry, array)` so the admin Method form actually renders.
- **`Block/Adminhtml/Method/Edit/Simulator.php`** — was reading `$this->_formKey` which doesn't exist on parent `\Magento\Backend\Block\Template`. Replaced with explicit `\Magento\Framework\Data\Form\FormKey` injection, so the Live Cart Simulator panel can submit its AJAX request with the form key.
- **`Block/Adminhtml/Rate/Edit.php`** — same Registry-injection bug as Method/Edit.php. Added `__construct(Context, Registry, array)` so the rate-rule Edit/New form opens without `Undefined property: $_coreRegistry`.
- **`Model/Csv/CsvExporter.php`** — `fputcsv()` was called without the `$escape` parameter on lines 56, 58 and 70, which on PHP 8.4 triggers `Deprecated Functionality: the $escape parameter must be provided as its default value will change`, breaking the CSV download with a fatal in developer mode. Added the `','`, `'"'`, `'\\'` triplet to all three calls so the exporter is PHP 8.4-safe and round-trips cleanly through the importer (which was already correct).

### Changed

- `Model/LicenseValidator.php` constructor extended from 2-arg (`ScopeConfigInterface`, `StoreManagerInterface`) to **5-arg** with `CacheInterface`, `Curl`, and `WriterInterface`. HMAC per-module + bundle paths remain identical. `MODULE_ID`, `SECRET_FRAGMENTS`, and `BUNDLE_SECRET_FRAGMENTS` constants are preserved byte-identical, so existing per-module HMAC keys and bundle keys continue to validate. Cache TTL is 60s for both valid and reject answers so portal IP-block events propagate within ~60s. Tri-state portal validation (`?bool`) means an explicit portal reject locks immediately — the 48h local grace only applies when the portal is unreachable.

### Migration

```
composer update etechflow/module-shipping-table-rates
bin/magento setup:upgrade
bin/magento cache:flush
```

If upgrading from v1.1.x: no schema or admin URL changes. After upgrade, visit **Stores → Configuration → eTechFlow → Shipping Table Rates → Payment** to enter Stripe keys if you intend to use the in-admin Checkout flow. Existing HMAC keys and bundle keys keep validating without any merchant action.

### Notes

- `License Portal URL` defaults to `https://subpanel-paralyses-president.ngrok-free.dev/license/validate` (the eTechFlow portal). For production change this to the eTechFlow-published portal URL when announced.
- The `Production Environment` toggle controls whether dev-host bypass applies. Default: Yes (enforce licensing). Set to No on dev/staging if your hostname isn't auto-detected.

---

## [1.1.1] — 2026-05-22 — Move admin menu under eTechFlow top-level sidebar

### Changed

- **STR admin pages relocated to a dedicated "eTechFlow" sidebar entry.** Previously the Methods list lived under `Sales → Operations`. Now it sits as a `Shipping Table Rates` column inside a new top-level `eTechFlow` sidebar entry (clusters with other paid-extension vendors above Magento's Stores). Matches the pattern Amasty / Magefan / MageWorx use.
- Each eTechFlow module declares the same `eTechFlow::root` + `eTechFlow::settings` + `eTechFlow::configuration` entries — Magento merges by id, so installing N modules still produces exactly one `eTechFlow` sidebar group.

### Migration

```
composer update etechflow/module-shipping-table-rates
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Admin URL routes unchanged (`etechflow_str/method/index` still works). No schema or behaviour changes — pure menu-layout adjustment.

---

## [1.1.0] — 2026-05-17 — Amasty parity pass

Closes the seven-feature gap STR had against Amasty Shipping Table Rates ($229–$829/yr) as documented in Amasty's 2022-10 user guide. After this release, STR matches Amasty's feature surface and keeps the four admin-UX differentiators no competitor offers: live cart simulator, versioned rate sets with one-click rollback, on-save conflict detection, and human-readable CSV columns.

Each "Added" entry below is independently reviewable. The seven features shipped as seven separate commits stacked on `main`:

| # | Feature | Commit |
|---|---|---|
| 1 | Weight Unit Conversion Rate per rate | `79e96b4` |
| 2 | `{day}` / `{name}` method-name template variables | `79e96b4` |
| 3 | "Use price after discount" + "Use price including tax" toggles | `04c60d3` |
| 4 | "Ship These Shipping Types for Free" per-method override | `9b82102` |
| 5 | CSV `delete_row` directive | `4047742` |
| 6 | Method-level store-view + customer-group scoping | `3256858` |
| 7 | Volumetric / dimensional weight (chargeable weight) | `aa665b9` |

Plus one infrastructure commit: PHPStan baseline restoration (`776c0ee`) — fixed a pre-existing `eTechFlow` vs `ETechFlow` case-sensitivity bug in `phpstan.neon` that had been silently masking ~80 errors on Linux. Workspace is now genuinely at 0 errors.

**Schema changes:** 5 new columns on `etechflow_str_rate` (`weight_unit_conversion_rate`, `delivery_label`, `name_delivery`), 8 new columns on `etechflow_str_method` (`use_price_after_discount`, `use_price_including_tax`, `ship_for_free_types`, `store_view_ids`, `customer_group_ids`, `use_volumetric_weight`, `volumetric_divisor`), 1 new CSV directive column (`delete_row`, not stored), 3 new product EAV attributes (`etechflow_length_cm`, `etechflow_width_cm`, `etechflow_height_cm`).

**Test count:** 95 → 281 STR-namespace tests (workspace total 419, 600 assertions, 0 deprecations).

### Added — Weight Unit Conversion Rate per rate

New `weight_unit_conversion_rate` column on `etechflow_str_rate` (decimal(12,6), NOT NULL, default `1.000000`). Cart weight is **divided** by this factor before the per-unit-of-weight component of the formula is applied — same semantics as Amasty's field of the same name. Example values:

- `1` (default) — no conversion
- `2.2046` — cart in lbs, billed in kg (10 lb cart ÷ 2.2046 ≈ 4.54 kg billed)
- `0.4536` — cart in kg, billed in lbs

The conversion **only** affects the per-kg formula term — weight-range conditions still match against the raw cart weight in the store unit. Negative or zero conversion factors are silently coerced to `1.0` in three layers (`Rate::getWeightUnitConversionRate`, `CsvImporter::prepareForInsert`, `Save` controller) so a bad input can't divide-by-zero the checkout formula.

**Where to set it:**
- Admin: Sales → Shipping Table Rates → method → rate rule edit form → Rate Formula fieldset
- CSV: new `weight_unit_conversion_rate` column (blank = 1.0 default)

**Verified:** 6 new unit tests in `RateCalculatorTest` covering default factor, lbs→kg, kg→lbs, zero coercion, negative coercion, and isolation from other formula components.

### Added — `{day}` / `{name}` method-name template variables

Method names can now embed two placeholders that are resolved at checkout from the winning rate's metadata:

- `{day}` — replaced by the rate's `delivery_label` (free-text, e.g. `to Canada, 5 working days`) if set, otherwise the integer `delivery_days`, otherwise empty.
- `{name}` — replaced by the rate's `name_delivery` (free-text, e.g. `Tracked 24` to turn a generic "Royal Mail {name}" method into "Royal Mail Tracked 24" at checkout).

Templates with **no** placeholders are returned unchanged — the legacy `"(X days)"` suffix that v1.0 appended from `delivery_days` is preserved for merchants who don't opt into the template syntax. Templates **with** placeholders take full control of the displayed title.

In mixed-shipping-type carts (multiple winning rates), the rate with the **longest** `delivery_days` is used as the substitution source — same customer-honest logic as `getLongestDeliveryDays()`. Empty substitutions don't leave double spaces: `MatchResult::interpolateMethodName` collapses runs of whitespace and trims.

**New schema columns** (both nullable, default NULL):
- `etechflow_str_rate.delivery_label` — varchar(128)
- `etechflow_str_rate.name_delivery` — varchar(128)

**Where to set them:**
- Admin: rate rule edit form → *Display & Priority* fieldset → "Delivery Label ({day} value)" and "Delivery Name ({name} value)" inputs.
- CSV: new `delivery_label` and `name_delivery` columns (blank = NULL = empty substitution / fall back to `delivery_days`).

**Verified:** 11 new unit tests in a new `MatchResultTest` covering unchanged templates, `delivery_label` priority, `delivery_days` fallback, empty fallback, `{name}` substitution, both-placeholders templates, longest-delivery winner selection across mixed-type carts, no-winner edge case, empty-label coercion, whitespace tidy. Existing `getLongestDeliveryDays` behaviour pinned by 2 regression tests in the same file.

### Added — "Use price after discount" + "Use price including tax" per method

Two boolean toggles on `etechflow_str_method` (both default 0 / NO) that change which subtotal the matcher uses for the method:

- `use_price_after_discount` — when YES, the **subtotal-range filter** (`subtotal_from`/`subtotal_to`) AND the **`rate_percent`** formula term see the post-discount subtotal. When NO (default), they see the pre-discount subtotal.
- `use_price_including_tax` — when YES, the subtotal used by this method is tax-inclusive. When NO (default), it's pre-tax.

The four combinations correspond to the four ways Magento exposes subtotals on a quote address. Each flag is per-method, so a merchant can have e.g. "UK Express — pre-tax, after discount" alongside "UK Standard — pre-tax, pre-discount" on the same store without conflict. Mirrors Amasty's per-method toggles of the same name.

**Architecture:** `CartContext` now carries all four subtotal variants (pre/post tax × pre/post discount); the legacy `$subtotal` field is preserved for back-compat and still resolves via the module-wide `Config::useDiscountedPrice()` setting. A new `CartContext::subtotalForMethod(Method)` picks the right variant from the method's flags. `RateMatcher::match()` resolves once per match and threads the value into both the subtotal-range filter and `RateCalculator::calculate()` via a new optional `$subtotalOverride` parameter. Old callers (and tests that construct `CartContext` directly without the variants) behave identically because `subtotalForMethod()` falls back to `$subtotal` when a variant is null.

**Where to set them:**
- Admin: Sales → Shipping Table Rates → method edit form → new *Subtotal Basis* fieldset → two Yes/No selects.
- CSV: out of scope — these are method-level (not rate-level) settings.

**Verified:** 5 new tests in `CartContextTest` (one per subtotal mode + null-variant fallback), 4 new tests in `RateMatcherTest` (post-discount filter match, pre-discount filter rejection, per-method `rate_percent`, default `rate_percent`), 3 new tests in `RateCalculatorTest` (`$subtotalOverride` replaces context subtotal, null override falls back, override doesn't leak into other terms).

### Added — "Ship These Shipping Types for Free" per-method override

New `ship_for_free_types` TEXT NULL column on `etechflow_str_method`. Stores a normalised, comma-separated list of lowercase `shipping_type` values that should ship at zero cost on this method. Mirrors Amasty's per-method "Ship These Shipping Types for Free" multiselect.

**Semantics** — the override applies AFTER matching but BEFORE multi-type aggregation:

1. RateMatcher matches candidate rates as usual (no filter change).
2. Per-group winners are picked as usual (one winner per shipping_type bucket).
3. **For each group whose `shipping_type` is in the method's free list, the cost contribution is forced to `0.0` (the winning rate is still recorded in `MatchResult::winningRates` so admin debugging / the live cart simulator shows what would have matched).**
4. Aggregation runs as usual on the per-group cost array.
5. Method-level `min_rate` / `max_rate` clamps apply AFTER aggregation — a method with `ship_for_free_types=[fragile]` and `min_rate=£2.50` still quotes £2.50 for an all-fragile cart, not £0.

**Wildcard rates (NULL `shipping_type`) are NEVER zeroed by this list.** They're the cart-level fallback and conceptually orthogonal to per-shipping-type freebies — zeroing them would silently turn ALL carts into free shipping, which is never what merchants want.

**Where to set it:**
- Admin: method edit form → new *Ship-for-Free Overrides* fieldset → comma-separated text input. Save controller normalises (split, lowercase, trim, dedupe, rejoin) before persisting.
- CSV: out of scope — method-level setting.

**Verified:** 8 new tests in a new `MethodTest` covering `getShipForFreeTypes` parsing (null / empty / whitespace / case / dedupe / trailing-comma) plus 5 new tests in `RateMatcherTest` (single-type cart fully zeroed, mixed cart zeroes only the listed type, wildcard rate not zeroed, mixed-case input normalised, method `min_rate` lifts zero result).

### Added — `delete_row` CSV import directive (Amasty parity)

New `delete_row` column on the CSV import/export format. Per-row directive: when `1` / `yes` / `true`, the row identifies an EXISTING rate to remove instead of being inserted. Blank / `0` = normal insert (back-compatible — existing CSVs work unchanged).

**Matching logic for the DELETE:** strict equality on every identifying condition column on the row — `country_code`, `region_code`, `city`, `zip_from`, `zip_to`, `weight_from`, `weight_to`, `qty_from`, `qty_to`, `subtotal_from`, `subtotal_to`, `customer_group_ids` (→ DB column `customer_group_id`), `shipping_type`. NULL on the CSV side means "match rows where the DB column IS NULL", so a blank `shipping_type` cell on a delete row targets the wildcard rates. Rate component columns (`rate_base`, `rate_per_product`, `rate_per_kg`, `rate_percent`) are **not** part of the matching identity — two rates with the same shape but different rates would be a conflict the `ConflictDetector` would already have caught.

If multiple existing rates match a single delete row, **all of them** are removed (matches Amasty's documented "remove all matching" behaviour). If zero rates match, a non-fatal warning is recorded against that row and the import still succeeds.

**Mode interaction:**
- `APPEND` (the natural fit): delete rows execute against existing data alongside the inserts. A single CSV can add some rates and remove others in one pass.
- `REPLACE`: the rate table is wiped before any rows run, so delete rows are inherently no-ops. Each delete row in REPLACE mode emits a warning: *"delete_row=1 in REPLACE mode is a no-op — the table was wiped before this row ran. Use APPEND mode if you want selective deletion."*

**Validation:** delete rows are allowed to have all four rate components blank/zero (a real DB rate must charge something; a delete row that just identifies an existing rate doesn't). Cross-column coherence checks (range ordering, postcode-from > postcode-to, customer-group format) still apply — a delete row with `weight_from=10, weight_to=5` is still an error because the implied range can't match any real rate.

**Transactional safety:** delete + insert run inside the same DB transaction that exists for the rest of the import. The pre-import `VersionRepository::snapshot()` is called before the transaction begins, so a successful-then-regretted delete-import can be rolled back via the existing version-history UI exactly like any other import.

**Schema:** zero DB changes. `delete_row` lives only in the CSV — it's a per-row directive, not state on the rate table.

**Where to set it:**
- Export → CSV now includes a `delete_row` column emitting `0` for every row. Flip individual rows to `1` in the editor before re-uploading.
- The existing import dropdown's `APPEND` mode is the right place to use it.

**ImportResult changes (back-compatible):**
- New fields: `rowsDeleted: int`, `warnings: array<int, string>` (keyed by 1-based row number).
- `success(int $rowsImported, int $rowsDeleted = 0, array $warnings = [])` — new args default to back-compat shape.
- `getSummary()` now reports inserts + deletes + warning count: *"Imported 12 rate rules, deleted 3. 1 warning."*

**Verified:** 2 new tests in `RateRowParserTest` (delete row skips zero-components validation; still validates range ordering) plus 6 new tests in a new `CsvImporterTest` covering APPEND-mode raw DELETE emission, NULL-column matching via `IS NULL`, no-match warning, mixed inserts + deletes in one CSV, REPLACE-mode warnings, and full identifying-column coverage.

### Added — Method-level store-view + customer-group scoping

Two new nullable text columns on `etechflow_str_method`:

- `store_view_ids`     — comma-separated Magento store IDs this method applies to. NULL = applies to ALL store views.
- `customer_group_ids` — comma-separated customer-group IDs this method applies to. NULL = applies to ALL groups.

Mirrors Amasty's per-method "Visible in Store View" + "Customer Groups" scopes. **Distinct from the per-RATE `customer_group_id` filter** (which is the existing rate-rule-level filter on `etechflow_str_rate`) — Feature 6's columns are the method-level scope, evaluated BEFORE rates are even loaded.

`Method::getStoreViewIds(): ?int[]` and `Method::getCustomerGroupIds(): ?int[]` share a private `parseIdCsv()` that drops non-numeric / negative entries, dedupes, and returns NULL when empty (= "applies to all"). Group `0` (NOT LOGGED IN) is a valid id, distinct from "null".

`CartContext` now carries `storeId: int` (default `0` = unknown / admin context). `CartContextBuilder` populates it from the address's quote OR the RateRequest's `store_id` field — both paths covered.

`RateMatcher::match()` short-circuits and returns null when:
- the method's `store_view_ids` is non-null AND doesn't contain `$context->storeId`; OR
- the method's `customer_group_ids` is non-null AND doesn't contain `$context->customerGroupId`.

Out-of-scope methods don't load their rate collection — much faster than filtering after-the-fact, and prevents incorrect rate-rule conflict warnings from firing against methods that don't apply to the current cart.

**Where to set them:**
- Admin: method edit form → new *Method Scope* fieldset → two comma-separated text inputs. Save controller normalises (trim, drop non-numeric, dedupe) before persisting.
- CSV: out of scope — these are method-level settings, not per-rate.

**Verified:** 7 new tests in `MethodTest` (null / empty / whitespace / non-numeric / negative / dedupe / group-zero edge case) plus 6 new tests in `RateMatcherTest` (null scope matches everything, store mismatch skips, store match passes, group mismatch skips, group-zero edge case, both scopes must match simultaneously).

### Added — Volumetric / dimensional weight (Amasty parity, biggest gap closed)

The headline feature of the parity pass — STR can now bill on **chargeable weight** instead of raw cart weight, the way couriers actually price parcels. A cart full of pillows is bulky but light; couriers charge on whichever is greater between actual weight and volumetric weight (`L × W × H ÷ divisor`).

**New columns** on `etechflow_str_method`:

- `use_volumetric_weight`  boolean  default 0
- `volumetric_divisor`     decimal(10,2) nullable  default NULL (= carrier-default 5000)

**New product attributes** (added via `AddProductDimensionAttributes` EAV patch — depends on `AddShippingTypeAttribute`):

- `etechflow_length_cm`   decimal, optional
- `etechflow_width_cm`    decimal, optional
- `etechflow_height_cm`   decimal, optional

The `etechflow_` prefix is deliberate. Many shipping modules add plain `length` / `width` / `height` (UPS, FedEx integrations, `ts_dimensions_*`); using the prefix prevents collisions if the merchant later installs anything from that ecosystem.

**Math:**

```
volumetric_kg = (Σ length × width × height × qty)  ÷  divisor
chargeable    = max(actual_cart_weight, volumetric_kg)
```

Divisor defaults: DHL / FedEx Air / Royal Mail Tracked = `5000`; FedEx Ground = `6000`; UPS small parcel premium = `4000`. Merchants pick the one their courier uses.

**RateMatcher** threads the chargeable weight through both:

1. The **weight-range filter** (`weight_from`/`weight_to` on the rate) — so a 2 kg cart in a 30×30×30 box (= 5.4 kg volumetric) correctly matches a rate scoped to "5–10 kg".
2. The **`rate_per_kg` formula term** via a new optional `$weightOverride` on `RateCalculator::calculate()`. The pre-existing `$weightConversionRate` factor still applies on top — so a method can do "volumetric in lbs billed in kg" by setting `use_volumetric_weight=YES` + `weight_unit_conversion_rate=2.2046`.

**Safety nets:**
- `Method::getVolumetricDivisor()` coerces 0 / negative / NULL inputs to the carrier-default 5000, so a corrupted column can't divide-by-zero the formula.
- `CartContext::chargeableWeightForMethod()` returns the actual weight unchanged when the method's flag is OFF — back-compat for every existing method.
- Carts without any dimension data produce `volumetricCm3 = 0`, and `max(weight, 0) = weight` — so it's **safe to flip the flag on before every product has dimensions filled in**. Per-product fill-out becomes a progressive enhancement, not a prerequisite.

**Performance:** `CartContextBuilder::loadCartProductData()` consolidates the existing `shipping_type` bulk lookup and the new dimension bulk lookup into ONE collection load. The product collection now also tracks per-product qty (replacing the old "set of IDs") so cm³ aggregates correctly when a cart has multiple of the same product.

**Where to set it:**
- Admin (method): method edit form → new *Volumetric / Dimensional Weight* fieldset → Yes/No flag + divisor input.
- Admin (product): the three `etechflow_*_cm` attributes appear in the "eTechFlow Shipping" attribute group on the product form.
- CSV: out of scope — these are method-level + product-level settings, not rate-level.

**Verified:** 6 new tests in `MethodTest` (`getUseVolumetricWeight` default false / casts truthy; `getVolumetricDivisor` defaults to 5000 / honours configured / coerces zero & negative / empty string), 5 new tests in `CartContextTest` (`chargeableWeightForMethod` actual-when-flag-off / volumetric-when-larger / actual-when-larger / different-divisor / no-dimensions fallback), 4 new tests in `RateCalculatorTest` (`$weightOverride` replaces per-kg basis / null falls back / composes with `$weightConversionRate` / doesn't leak into other terms), 5 new tests in `RateMatcherTest` (weight-range filter uses actual when flag off / uses volumetric when larger + opted in / `rate_per_kg` term uses chargeable / custom divisor applies end-to-end / safe with no dimensions).

---

## [1.0.0] — 2026-05-16

### First production release

After 5 internal phases, this is the merchant-facing v1 — feature-complete, end-to-end-tested in container, ready to ship.

**Positioning**: the merchant-friendly alternative to Amasty Shipping Table Rates ($229/yr Community) and MageWorx Shipping Table Rates ($149/yr base + $149 Commerce). Matches their feature surface, beats them on day-to-day admin UX through 4 differentiators no competitor offers (verified May 2026).

### Major capabilities

**Rate engine**
- Unlimited shipping methods + unlimited rate rules per method
- Full condition surface: country / region / city / postcode-range / weight-range / qty-range / subtotal-range / customer-group / shipping-type (5 seeded buckets — Standard / Fragile / Oversized / Hazmat / Cold Chain)
- Alphanumeric postcode ranges work (UK / Canada / Netherlands)
- Rate formula: `base + (per_product × qty) + (per_kg × weight) + (percent × subtotal)` with method-level min/max clamps
- Multi-shipping-type carts: choose sum / min / max aggregation per method
- Deterministic tie-breaking via sort_order; lower wins
- Custom `shipping_type` product attribute seeded automatically with extensible options
- Native Magento MSI support (carrier called per source allocation — no bolt-on package required, unlike Amasty's `amasty/module-shipping-table-rates-msi-performance`)
- Native Hyvä Theme + Hyvä Checkout support (rates surface via standard Magento rate display API)

**Admin UI**
- Methods listing grid under *Sales → Operations → Shipping Table Rates*
- Method edit form: code, name, active, sort_order, min/max clamps, multi-type mode
- **Inline rates table** under each method (this release) — shows existing rate rules with Edit / Delete per row, plus "Add Rate Rule" button
- Dedicated rate edit form with full condition surface + the 4-component formula + checkout metadata (delivery_days, comment)
- Module Status banner at top of admin config (6 states, same pattern as NDE / BED)

**4 v1.0 differentiators vs Amasty / MageWorx**

1. **Live cart simulator** — type a hypothetical cart (country / weight / qty / subtotal / shipping types) on the method edit page, click Simulate, see exactly which methods match, what they cost, which rate row contributed, and the formula breakdown. Replaces "drive a real checkout to debug" with one click. Neither competitor offers this.

2. **Versioned rate sets** — every method save / CSV import / delete / rate edit snapshots the prior state into `etechflow_str_version`. Inline panel on the method edit page lists the 25 most recent snapshots with one-click Restore. Rollback itself snapshots first — undo-the-undo works. Amasty's 2025 backward-incompatible update broke installs with no easy recovery; this prevents that.

3. **Conflict detection on save** — every save scans for rate-rule overlaps with merchant-readable explanations (`rate_id=42 <-> rate_id=57: country=GB, weight range overlap (SAME sort_order — winner is non-deterministic, set different sort_order to pick one)`). Reported as admin notices (not errors). Catches misconfigurations before customers hit them.

4. **Human-readable CSV columns** — `rate_base`, `rate_per_product`, `rate_per_kg`, `rate_percent`, `delivery_days`, `comment`, etc. Designed in deliberate opposition to Amasty's cryptic `PPP/FRPP/FRPUW` codes that merchants reverse-engineer from sample files. Per-row validation collects ALL errors across the file (not fail-on-first) and reports them with row numbers. Atomic import — partial CSV imports never happen.

**CLI verify**
- `bin/magento etechflow:str:simulate --country=GB --weight=5 --qty=3 --subtotal=100` — runs the matching pipeline against active methods, prints structured output, exit code 0 on match / 1 on no match. Same pattern as `etechflow:nde:verify`. CI / monitoring ready.

**Licensing**
- HMAC-based per-domain key validation with dev-host bypass (`*.test`, `localhost`, `staging.*`, `*.magento.cloud`, ngrok, RFC 1918 IPs, etc.) — same proven pattern as NDE / BED
- "Production Environment = No" toggle for non-standard dev domains
- Bundle key support (one bundle key activates all 3 eTechFlow modules)

### Testing

- **267 unit tests** across the workspace, 369 assertions
- 95 of those tests cover STR alone: CartContext / RateCalculator / RateMatcher / LicenseValidator / RateRowParser / ConflictDetector
- **PHPStan level 4**: 0 errors workspace-wide
- **`setup:di:compile`**: clean
- End-to-end verified in test container: methods seeded → rates seeded → CLI simulate confirms `£7.99 base + £0.50/kg × 8kg = £11.99` matches the formula
- Country filter exclusion verified (GB rules don't apply to US cart)

### Compatibility

- Magento Open Source 2.4.4+ / Adobe Commerce 2.4.4+
- PHP 8.1, 8.2, 8.3, 8.4
- Hyvä Theme + Hyvä Checkout (via standard rate display API — no theme-specific code required)
- MSI / multi-source inventory (transparent — carrier is called per source allocation)

---

## Phase history (development snapshots — not separate releases)

The 5 development phases below were built sequentially in the same release cycle; they're listed here for engineering reference. Customers see only v1.0.0.

### [0.5.0] — Phase 5 — admin differentiators
Live cart simulator widget + version-history panel + conflict detection on save. The Amasty/MageWorx UX gap closers.

### [0.4.0] — Phase 4 — checkout carrier integration
`Model/Carrier/TableRates` extending `AbstractCarrier` + `CarrierInterface`. Registered under `carriers/etechflow_str/`. CLI simulate command. MSI works transparently via standard Magento carrier contract.

### [0.3.0] — Phase 3 — admin CRUD + CSV import/export + versioning
Methods grid + edit form, CSV import with per-row validation, CSV export, VersionRepository with snapshot-before-save hook.

### [0.2.0] — Phase 2 — rate-lookup engine
Method + Rate data models + resource models, CartContext value object, CartContextBuilder, RateCalculator (formula), RateMatcher (lookup algorithm), MatchResult, 50+ unit tests.

### [0.1.0] — Phase 1 — module foundation
Skeleton, db_schema, EAV patch for `shipping_type` attribute, LicenseValidator (HMAC + dev-host bypass + bundle key), Config, Module Status banner, ACL.
