# Shipping Table Rates — User Guide

A complete reference for configuring + operating the module. If you're just installing, start with `README.md` for the 4-step setup.

---

## What the module actually does

Adds a shipping carrier to Magento that returns one or more rates per customer cart based on a flexible rule engine. Each rule combines conditions (country / region / postcode / weight / qty / subtotal / customer group / product type) with a rate formula (base + per-product + per-kg + percent), and the engine picks the best-matching rule per method at checkout time.

The module is invoked by Magento's shipping subsystem — same lifecycle as any other carrier (Flat Rate, UPS, Royal Mail). MSI orchestrates it per source allocation, so multi-source carts work transparently.

## Admin layout — where things live

### Module-wide settings

`Stores → Configuration → eTechFlow → Shipping Table Rates`

- **Module Status** — banner showing one of 6 states with diagnostic guidance
- **License** — Production Environment toggle + License Key
- **General Settings** — master Enable Module / Use Discounted Subtotal / Configured Rates Include Tax / Detect Overlapping Rules

### Carrier settings

`Stores → Configuration → Sales → Shipping Methods → eTechFlow Shipping Table Rates`

Standard Magento carrier toggles: Enabled at Checkout / Carrier Title (customer-facing) / Ship-to Countries / Show If Not Applicable / Sort Order / Displayed Error Message.

### Method + rate management

`Sales → Operations → Shipping Table Rates`

Methods grid → click a method or "Add New Method" → method edit page with 5 panels stacked vertically:

1. **General + Limits** — method-level settings (code, name, sort_order, min/max clamps, multi-type-mode)
2. **Rate Rules** — list of rules + Add / Edit / Delete actions
3. **CSV Import / Export** — bulk-edit via spreadsheet
4. **Live Cart Simulator** — type a cart, see which rates apply
5. **Version History** — list of automatic snapshots with one-click Restore

## Anatomy of a rate rule

Each row in `etechflow_str_rate` is a single rule with the following columns:

### Cart conditions (all nullable — NULL means "match any value")

| Column | Type | Notes |
|---|---|---|
| `country_code` | string(2) | ISO 3166-1 alpha-2 (`GB`, `US`, `DE`). NULL = any |
| `region_code` | string(32) | State or region code/name. Case-insensitive. NULL = any |
| `city` | string(128) | Exact city name (case-insensitive). NULL = any |
| `zip_from` | string(32) | Postcode range start, alphanumeric. NULL = no lower bound |
| `zip_to` | string(32) | Postcode range end. NULL = no upper bound |
| `weight_from` / `weight_to` | decimal | Cart weight range in store unit (kg / lb) |
| `qty_from` / `qty_to` | int | Cart item-count range |
| `subtotal_from` / `subtotal_to` | decimal | Cart subtotal range, store currency |
| `customer_group_id` | string | Comma-separated group IDs (`1,3,5`). NULL = any |
| `shipping_type` | string | Value of product `shipping_type` attribute. NULL = any |

### Rate formula (all NOT NULL, default 0)

| Column | Notes |
|---|---|
| `rate_base` | Flat charge added once per matching cart |
| `rate_per_product` | Charge × cart item qty |
| `rate_per_kg` | Charge × cart weight (in store unit) |
| `rate_percent` | Charge as % of subtotal (0–100, NOT 0.0–1.0) |

Final cost = `rate_base + (rate_per_product × qty) + (rate_per_kg × weight) + (rate_percent × subtotal / 100)`, then clamped by method-level `min_rate` and `max_rate`.

### Metadata

| Column | Notes |
|---|---|
| `delivery_days` | Estimated ETA shown next to method title at checkout (null = hide) |
| `comment` | Optional text under the method at checkout. Safe HTML (b/i/u) allowed |
| `sort_order` | Lower wins when multiple rules match. Use distinct values to avoid non-determinism |
| `is_active` | 1/0 — per-row on/off without deleting |

## How the matcher picks a rule

1. **SQL pre-filter**: load active rates for the method, filter by country (NULL or exact match), order by `sort_order ASC` then `rate_id ASC`
2. **Per-row condition check** (PHP): for each remaining rate, check all non-NULL conditions against the cart. A rate is a candidate only if EVERY non-NULL condition matches.
3. **Group by shipping_type**: rates with NULL `shipping_type` form the wildcard bucket; rates targeting a specific type apply only if a cart item has that type
4. **Winner per group**: lowest `sort_order` wins; `rate_id` ASC breaks ties deterministically
5. **Aggregate per method's multi-type mode**: `sum` (default) / `min` / `max`
6. **Clamp by method min/max**: final cost is bounded

## Multi-shipping-type carts — what happens

Example: cart contains 1× fragile item + 1× standard item. Method has 3 rules:

- Rate A: `shipping_type` = NULL, `rate_base` = $5  (wildcard — always applies)
- Rate B: `shipping_type` = "fragile", `rate_base` = $3
- Rate C: `shipping_type` = "standard", `rate_base` = $2

Cart has both fragile + standard types present.

| Multi-Type Mode | Result | Reasoning |
|---|---|---|
| `sum` (default) | $5 + $3 + $2 = $10 | All matching rates added |
| `min` | $2 | Cheapest matching rate |
| `max` | $5 | Most expensive matching rate |

Most merchants want `sum`. `min` / `max` are useful when shipping types represent mutually-exclusive service tiers (the most/least demanding item dictates the whole shipment cost).

## CSV format

Single source of truth: `Model/Csv/CsvSchema.php`. Column order in the export file matches the import expectation — round-trips cleanly through Excel / Numbers / Sheets.

### Columns (in CSV order)

| Column | Type | Notes |
|---|---|---|
| `country_code` | string | ISO alpha-2; blank = any |
| `region_code` | string | State / region; blank = any |
| `city` | string | Blank = any |
| `zip_from` | string | Alphanumeric postcode range start; blank = no lower bound |
| `zip_to` | string | Blank = no upper bound |
| `weight_from` | float | In store unit; blank = no lower bound |
| `weight_to` | float | Blank = no upper bound |
| `qty_from` | int | Blank = any |
| `qty_to` | int | Blank = any |
| `subtotal_from` | float | Blank = any |
| `subtotal_to` | float | Blank = any |
| `customer_group_ids` | string | Comma-separated IDs (`1,3,5`); blank = any |
| `shipping_type` | string | Lowercased; blank = any |
| `rate_base` | float | Flat charge |
| `rate_per_product` | float | Per-item charge |
| `rate_per_kg` | float | Per-weight-unit charge |
| `rate_percent` | float | 0–100 |
| `delivery_days` | int | Blank = hide ETA at checkout |
| `comment` | string | Free text |
| `sort_order` | int | Lower = higher priority |
| `is_active` | bool | `1` / `0` / `yes` / `no` / `true` / `false` / `y` / `n` |

### Type coercion

The parser is forgiving:

- **Floats**: accepts `5.99`, `5,99` (European decimal), `1,234.56` (US thousands separator), `1.234,56` (European thousands)
- **Booleans**: `1` / `0` / `yes` / `no` / `true` / `false` / `y` / `n` / `t` / `f` / `on` / `off`
- **Integers**: must be plain digits (`100`, not `100.0`)

### Validation

Per-row validations run BEFORE any rows are written. If any row fails, NOTHING is written — atomic import.

Each row's errors are collected (not fail-on-first), so the import report shows every issue in one pass:

```
Row 12: weight_from is not a valid number — got 'free'
Row 15: zip_from is greater than zip_to (the range is empty — no postcode can match)
Row 22: all four rate components are zero — this rule would always charge £0
```

Cross-column validations include: range inversions (weight, qty, subtotal, zip), all-zero-rate warning (suppress with `rate_base=0.001` if intentional).

### Import modes

- **Replace** — wipe all existing rates for the method, then insert from CSV. Use for full table rewrites.
- **Append** — keep existing rates, add CSV rows alongside. Use for incremental additions.

Both modes snapshot the pre-import state into the version history before mutating, so a bad import is reversible.

## CLI verification

```bash
bin/magento etechflow:str:simulate \
    --country=GB \
    --region=ENG \
    --postcode="SW1A 1AA" \
    --weight=5 \
    --qty=3 \
    --subtotal=100 \
    --customer-group=1 \
    --shipping-types=fragile,standard
```

Walks all active methods, runs the matcher per method, prints structured output:

```
Method "UK Standard Delivery" (code: uk_standard)
  Total cost: 11.99
  Winning rate(s): 1 row(s)
    - rate_id=3  shipping_type=(any)  formula=base=7.99 + per_kg=0.50
```

**Exit codes**: 0 if at least one method matched, 1 if none matched. Drop into CI / monitoring to detect rate regressions.

**Options**:

| Option | Default | Notes |
|---|---|---|
| `--country` | `GB` | ISO alpha-2 |
| `--region` | `''` | State / region code or name |
| `--city` | `''` | City name |
| `--postcode` | `''` | Alphanumeric |
| `--weight` | `1.0` | Float |
| `--qty` | `1` | Int |
| `--subtotal` | `50.0` | Float |
| `--customer-group` | `1` | Int (0 = guest) |
| `--shipping-types` | `''` | Comma-separated values |

## Versioning + rollback

Every method save, rate save, rate delete, method delete, and CSV import creates an automatic snapshot of the method + its rates into `etechflow_str_version` as a JSON blob.

The Version History panel on the method edit page lists the 25 most recent snapshots with:
- Timestamp (UTC)
- Label (merchant-supplied or auto-generated)
- Admin user who triggered the snapshot
- Restore button

Clicking Restore:
1. Auto-snapshots the CURRENT state (with label "Pre-rollback auto-snapshot")
2. Replaces the method + rates with the snapshot state
3. Redirects back to the method edit page with a success flash

Because the rollback itself snapshots first, undo-the-undo always works — merchants can restore the wrong version and then restore back.

## Conflict detection

When **Detect Overlapping Rules in Admin** is enabled (default), every method save / rate save runs the conflict detector across all active rates of that method.

The detector flags two flavours:

**Plain overlap** (informational): two rules could match the same cart. Often intentional (wildcard + type-specific override is a valid pattern).

```
Conflict: rate_id=42 <-> Overlaps with rate_id=57: country=GB, weight range overlap
```

**Non-deterministic winner** (real bug): two overlapping rules have the SAME `sort_order`. The matcher's tie-breaker is `rate_id ASC` — deterministic at runtime, but probably NOT what the merchant intended.

```
Conflict: rate_id=42 <-> Overlaps with rate_id=57: country=GB, weight range overlap (SAME sort_order — winner is non-deterministic, set different sort_order to pick one)
```

Reported as admin **notices**, not errors. Merchants decide if the overlap is intentional. Capped at 5 visible messages per save with "and N more" rollup.

Disable via **Detect Overlapping Rules = No** for high-volume merchants (thousands of rules) who want to skip the per-save scan cost.

## Hyvä support

Magento's standard shipping subsystem hands our carrier's rates to whatever checkout is active. Hyvä Checkout uses the same Rate\Method API as Luma checkout, so our rates surface without any theme-specific code.

If you want to customise how the rate's `comment` (checkout instructions) renders on Hyvä, override `view/frontend/templates/checkout/...` in your theme — the comment is exposed via the rate's `method_description` data field.

## MSI support

Magento's Multi-Source Inventory orchestrates source allocations and calls each shipping carrier per allocation. Our carrier's `collectRates()` just returns rates for the items it's given — MSI works transparently with zero MSI-specific code on our side. No bolt-on `*-msi-performance` package required (unlike Amasty).

## Known limitations / not supported in v1.0

A short, honest list:

- **Volumetric / dimensional weight calculation** is not implemented. The matcher uses Magento's standard weight calculation. Roadmap for v1.1 based on customer feedback.
- **Tax-aware rate clamping** is single-mode: the `Configured Rates Include Tax` flag is global, not per-method. Per-method tax mode is a v1.1 candidate.
- **Inline AJAX rate editor**: the current rate editor is a separate add/edit page accessed from the method edit page. Inline AJAX editing within the rates table is a v1.x polish item.
- **Amasty CSV migration tool**: planned for v1.1 once we have customer feedback on which columns to map. v1.0 merchants migrating from Amasty manually map columns.
- **Free-shipping coupon override**: when Magento's native free-shipping coupon applies, the cart subtotal Magento sees in our `RateRequest` is the post-coupon value. The `free_shipping_compatible` method flag (default Yes) means we still return our calculated rate; setting it to No is a v1.1 feature.

## Troubleshooting

### "I configured everything but no rates appear at checkout"

In order:

1. **Module Status banner** at top of admin config — what does it say?
2. **`carriers/etechflow_str/active` = Yes** under Sales → Shipping Methods
3. **`etechflow_shippingtablerates/general/enabled` = Yes** under eTechFlow config
4. **At least one active method** with at least one active rate that matches the cart you're testing
5. Run `bin/magento etechflow:str:simulate --country=<your test country> --weight=<your test weight> ...` — does it report a match?

### "Conflict detection fired but I'm sure my rules are different"

The detector treats NULL as "match any" — a rule with NULL country overlaps a rule with country=GB because the wildcard rule applies in GB too. This is correct behaviour. If you intended the NULL-country rule to be a fallback that only applies when no specific rule matches, set its `sort_order` higher than the specific ones (lower sort_order wins).

### "CSV import says 'all four rate components are zero'"

A rule with `rate_base=0`, `rate_per_product=0`, `rate_per_kg=0`, `rate_percent=0` would always charge £0 — almost certainly a mistake. If you genuinely want a £0-cost rate (e.g. promotional free shipping for a condition), set `rate_base=0.001` to suppress the warning. Magento rounds it back to £0 at display.

### "How do I check the matcher is doing what I think?"

```bash
bin/magento etechflow:str:simulate ...
```

Returns the full pipeline output: which method matched, which rate row contributed, the formula breakdown, and the calculated total. This is the same logic the checkout uses — if simulate says it matches, checkout will too.
