# Shipping Table Rates for Magento 2

**Flexible shipping table rates without the spreadsheet horror.**

Visual rate management. Live cart simulator. One-click rollback. Conflict detection. Native MSI + Hyvä. Designed as the merchant-friendly alternative to Amasty / MageWorx — matching their feature surface, beating them on day-to-day admin UX.

| | Amasty | MageWorx | **eTechFlow** |
|---|---|---|---|
| Community / Open Source | $229/yr | $149/yr | **$129/yr** |
| Adobe Commerce | $529/yr | +$149/yr | **$299/yr** |
| Magento Cloud | $829/yr | n/a | **$399/yr** |
| Live cart simulator in admin | ❌ | ❌ | ✅ |
| Versioning + one-click rollback | ❌ | ❌ | ✅ |
| Conflict detection on save | ❌ | ❌ | ✅ |
| Human-readable CSV columns | ❌ (PPP/FRPP/FRPUW) | partial | ✅ |
| Native MSI | bolt-on package | partial | ✅ |
| Hyvä Checkout | ✅ | partial | ✅ |
| CLI smoke-test command | ❌ | ❌ | ✅ |

---

## What it does

Adds an unlimited number of shipping methods to your Magento 2 / Adobe Commerce checkout, each driven by table rates with rich conditions:

- **Destination**: country, region, city, postcode range (alphanumeric — UK / Canada / Netherlands work)
- **Cart**: weight range, qty range, subtotal range
- **Customer**: customer group(s)
- **Product**: a `shipping_type` attribute (seeded with Standard / Fragile / Oversized / Hazmat / Cold Chain — extensible)
- **Rate formula**: base + per-product + per-kg + percent of subtotal, combined freely, with method-level min/max clamps and per-multi-type-cart aggregation (sum / min / max)

## Requirements

| | |
|---|---|
| **Magento** | Open Source 2.4.4+ OR Adobe Commerce 2.4.4+ |
| **PHP** | 8.1, 8.2, 8.3, or 8.4 |
| **Compatible themes** | Luma + Hyvä + Hyvä Checkout |
| **MSI** | Supported transparently via standard carrier contract |

## Installation

### Option A — Composer (recommended)

```bash
composer require etechflow/module-shipping-table-rates:^1.0
bin/magento module:enable ETechFlow_ShippingTableRates
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Option B — Manual (from zip)

1. Unzip `etechflow-module-shipping-table-rates-1.0.0.zip` into:
   ```
   <magento-root>/app/code/ETechFlow/ShippingTableRates/
   ```
   **The directory MUST be named `ETechFlow` (capital E, capital T, capital F) — case-sensitive on Linux servers.**

2. Enable and set up:
   ```bash
   bin/magento module:enable ETechFlow_ShippingTableRates
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

3. Verify:
   ```bash
   bin/magento module:status | grep ShippingTableRates
   ```

## After install — 4-step setup

### Step 1 — Enter your licence key

Admin → **Stores → Configuration → eTechFlow → Shipping Table Rates → License**

Paste the key from your purchase email.

> **Don't have a key yet?** Free on dev/staging environments — any host matching `localhost`, `*.test`, `*.local`, `staging.*`, `*.magento.cloud`, ngrok, or RFC 1918 IPs runs at full features without a licence. For non-standard dev domains, set **Production Environment = No** instead.

### Step 2 — Verify the module is active

The "Module Status" banner at the top of the config section will show ✅ **Module is active** (or one of 5 other diagnostic states with what-to-do guidance).

### Step 3 — Create your first method

Admin → **Sales → Operations → Shipping Table Rates → Add New Method**

Fill in:
- **Code**: stable machine identifier (`uk_standard`, `eu_express`)
- **Name**: customer-facing label at checkout
- **Active**: Yes
- **Sort Order**: 10 (lower = higher up in checkout list)
- **Min / Max Rate**: optional clamps
- **Multi-Type Handling**: `sum` (default) / `min` / `max` — only matters when rates target specific `shipping_type` values

Save. The Rate Rules + Versions + Simulator + CSV panels appear below.

### Step 4 — Add rate rules

**Option A — Inline editor** (best for a few rules):
Click **Add Rate Rule** on the method edit page. Fill in the conditions you want (leave blank for "any"), the rate components, and Save.

**Option B — CSV import** (best for bulk):
Click **Download CSV** to get the column template, edit in your spreadsheet editor, upload via **Upload + Import**. Choose **Replace** or **Append**.

## Key admin features (all on the method edit page)

| Panel | What it does |
|---|---|
| **Rate Rules** | List + add + edit + delete individual rules. Each row shows conditions in a compact view + the formula + per-row Edit / Delete. |
| **CSV Import / Export** | Bulk-edit via spreadsheet. Round-trips cleanly — export, edit, re-import. Per-row validation with all errors collected before any rows are written. |
| **Live Cart Simulator** | Type a hypothetical cart (country / weight / qty / subtotal / shipping types), click Simulate. See exactly which methods match, the total cost, which rate row contributed, and the formula breakdown. Replaces the "drive a real checkout to debug" workflow. |
| **Version History** | Lists the 25 most recent snapshots with one-click Restore. Every save / import / delete creates a snapshot. Rollback itself snapshots first — undo-the-undo works. |

## CLI verification

```bash
bin/magento etechflow:str:simulate \
    --country=GB \
    --postcode="SW1A 1AA" \
    --weight=5 --qty=3 --subtotal=100 \
    --customer-group=1 \
    --shipping-types=fragile,standard
```

Prints structured output showing which methods matched, total cost, winning rate IDs, formula breakdown. Exit code 0 on match, 1 on no match — drop into CI / monitoring.

## Documentation

| File | Read when |
|---|---|
| `README.md` (this file) | First — overview + install + 4-step setup |
| `docs/USER_GUIDE.md` | Full reference: every field, every condition, every CSV column, troubleshooting |
| `CHANGELOG.md` | What changed in each version |
| `LICENSE.txt` | Licence terms |

## Bundle pricing

Paired with **Next Day Eligibility** + **Backorder ETA Display** in the **eTechFlow 3-Module Bundle** — one licence key activates all three.

## Support

- **Email**: support@etechflow.com — typically responds within one business day
- **Website**: https://etechflow.com

## License

Proprietary — see `LICENSE.txt`. Licensed per Magento installation, with unlimited dev/staging environments under the same business entity.

To change your production domain (e.g. site migration), email `support@etechflow.com` with your old + new domain and order number. New key issued same business day.
