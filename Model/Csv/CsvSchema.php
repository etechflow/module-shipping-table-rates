<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Csv;

/**
 * The CSV column contract — single source of truth for column names, order,
 * types, and human-readable labels.
 *
 * Designed in deliberate opposition to Amasty's CSV format. Theirs uses
 * cryptic codes (PPP, FRPP, FRPUW) that merchants reverse-engineer from
 * sample files. Ours uses descriptive snake_case names a merchant can read
 * directly:
 *
 *   country_code, region_code, city, zip_from, zip_to,
 *   weight_from, weight_to, qty_from, qty_to, subtotal_from, subtotal_to,
 *   customer_group_ids, shipping_type,
 *   rate_base, rate_per_product, rate_per_kg, rate_percent,
 *   delivery_days, comment, sort_order, is_active
 *
 * Same data shape as the DB columns (one row per rate rule), but with the
 * NULL-able semantics expressed as empty strings (Amasty uses '*' for "any"
 * — we use empty for compatibility with standard CSV editors and clarity).
 */
class CsvSchema
{
    /**
     * Ordered list of column keys + type metadata. The export writer emits
     * columns in THIS exact order; the importer validates against it.
     */
    public const COLUMNS = [
        // Destination conditions
        'country_code'       => ['label' => 'Country (ISO alpha-2)',         'type' => 'string', 'desc' => 'GB / US / DE / blank=any'],
        'region_code'        => ['label' => 'Region / State',                'type' => 'string', 'desc' => 'State code or region name; blank=any'],
        'city'               => ['label' => 'City',                          'type' => 'string', 'desc' => 'City name; blank=any'],
        'zip_from'           => ['label' => 'Postcode range start',          'type' => 'string', 'desc' => 'Alphanumeric — UK postcodes work; blank=no lower bound'],
        'zip_to'             => ['label' => 'Postcode range end',            'type' => 'string', 'desc' => 'Alphanumeric; blank=no upper bound'],

        // Cart conditions (ranges, inclusive)
        'weight_from'        => ['label' => 'Cart weight from',              'type' => 'float',  'desc' => 'In store unit (kg or lb); blank=no lower bound'],
        'weight_to'          => ['label' => 'Cart weight to',                'type' => 'float',  'desc' => 'Inclusive upper bound; blank=no upper bound'],
        'qty_from'           => ['label' => 'Item qty from',                 'type' => 'int',    'desc' => 'Total cart qty; blank=any'],
        'qty_to'             => ['label' => 'Item qty to',                   'type' => 'int',    'desc' => 'Inclusive upper bound'],
        'subtotal_from'      => ['label' => 'Cart subtotal from',            'type' => 'float',  'desc' => 'In store currency; blank=any'],
        'subtotal_to'        => ['label' => 'Cart subtotal to',              'type' => 'float',  'desc' => 'Inclusive upper bound'],

        // Customer + product conditions
        'customer_group_ids' => ['label' => 'Customer group IDs',            'type' => 'string', 'desc' => 'Comma-separated group IDs (1,3,5); blank=any group'],
        'shipping_type'      => ['label' => 'Shipping type',                 'type' => 'string', 'desc' => 'standard/fragile/oversized/hazmat/cold (or your custom); blank=any'],

        // Rate formula components
        'rate_base'                    => ['label' => 'Base rate',              'type' => 'float',  'desc' => 'Flat charge per cart'],
        'rate_per_product'             => ['label' => 'Per-product rate',       'type' => 'float',  'desc' => 'Charge × cart qty'],
        'rate_per_kg'                  => ['label' => 'Per-kg rate',            'type' => 'float',  'desc' => 'Charge × billing weight (cart weight ÷ weight_unit_conversion_rate)'],
        'rate_percent'                 => ['label' => 'Percent of subtotal',    'type' => 'float',  'desc' => '0–100; 5 means 5%'],
        'weight_unit_conversion_rate'  => ['label' => 'Weight unit conversion', 'type' => 'float',  'desc' => 'Cart weight is DIVIDED by this before per-kg. Default 1 = no conversion. 2.2046 converts lbs→kg, 0.4536 converts kg→lbs.'],

        // Metadata
        'delivery_days'      => ['label' => 'Estimated delivery (days)',     'type' => 'int',    'desc' => 'Shown at checkout; blank=hide. Also picks the slowest winner for {day} substitution in mixed-type carts.'],
        'delivery_label'     => ['label' => 'Delivery label ({day} value)',  'type' => 'string', 'desc' => 'Free-text label substituted for the {day} placeholder in the method name (overrides delivery_days for display). Blank = use delivery_days.'],
        'name_delivery'      => ['label' => 'Delivery name ({name} value)',  'type' => 'string', 'desc' => 'Free-text label substituted for the {name} placeholder in the method name (e.g. "Tracked 24" for a "Royal Mail {name}" method).'],
        'comment'            => ['label' => 'Checkout comment',              'type' => 'string', 'desc' => 'Free text shown under the method at checkout'],
        'sort_order'         => ['label' => 'Rule priority',                 'type' => 'int',    'desc' => 'Lower wins when multiple rules match'],
        'is_active'          => ['label' => 'Active',                        'type' => 'bool',   'desc' => '1 / 0 / yes / no / true / false'],

        // Directive column — NOT a DB column. When TRUE on an import row, the
        // row identifies an EXISTING rate to delete instead of being inserted.
        // The matching uses every other non-null condition column on the row
        // (country / region / city / zip range / weight range / qty range /
        // subtotal range / customer group / shipping type). Blank/FALSE = insert.
        'delete_row'         => ['label' => 'Delete row',                    'type' => 'bool',   'desc' => '1 = delete an existing rate matching the other condition columns on this row (Amasty parity). Blank/0 = insert as usual.'],
    ];

    /**
     * Return the list of column keys in CSV order.
     *
     * @return string[]
     */
    public static function getColumnKeys(): array
    {
        return array_keys(self::COLUMNS);
    }

    /**
     * Return the human-readable header row for export files.
     *
     * @return string[]
     */
    public static function getHeaderRow(): array
    {
        return self::getColumnKeys();  // header = the snake_case keys (deliberate — easier for re-import)
    }
}
