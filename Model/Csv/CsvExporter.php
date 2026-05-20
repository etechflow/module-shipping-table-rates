<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Csv;

use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\Rate;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;

/**
 * Export a method's rates to CSV.
 *
 * Writes the canonical column order from CsvSchema so the exported file
 * round-trips cleanly through CsvImporter — merchants can export, edit in
 * Excel/Numbers/Sheets, re-import. NULL DB values become empty CSV cells
 * (NOT the literal string "NULL").
 *
 * Caller supplies the output handle so this is filesystem-free for tests.
 */
class CsvExporter
{
    /**
     * Constructor.
     *
     * @param RateCollectionFactory $rateCollectionFactory
     */
    public function __construct(
        private readonly RateCollectionFactory $rateCollectionFactory
    ) {
    }

    /**
     * Write all rates for a method to the given handle as CSV.
     *
     * @param Method   $method
     * @param resource $outputHandle  Open write-capable handle (e.g. php://temp)
     * @return int  Number of rate rows written (header excluded)
     */
    public function export(Method $method, $outputHandle): int
    {
        if (!is_resource($outputHandle)) {
            throw new \InvalidArgumentException('Output handle must be a resource.');
        }

        $methodId = $method->getMethodId();
        if ($methodId === null) {
            // No method, no rates — still write the header so the merchant
            // gets a populatable template
            fputcsv($outputHandle, CsvSchema::getHeaderRow());
            return 0;
        }

        $collection = $this->rateCollectionFactory->create();
        $collection->addFieldToFilter('method_id', $methodId);
        $collection->setOrder('sort_order', 'ASC');

        fputcsv($outputHandle, CsvSchema::getHeaderRow());

        $written = 0;
        foreach ($collection as $rate) {
            fputcsv($outputHandle, $this->rateToRow($rate));
            $written++;
        }

        return $written;
    }

    /**
     * Convert a Rate model to an indexed array in CsvSchema column order.
     *
     * @param Rate $rate
     * @return array
     */
    private function rateToRow(Rate $rate): array
    {
        $row = [];
        foreach (CsvSchema::getColumnKeys() as $key) {
            $row[] = $this->formatColumnValue($rate, $key);
        }
        return $row;
    }

    /**
     * Format a single column value for CSV — null → empty string, booleans
     * → 1/0, everything else via string cast.
     *
     * @param Rate   $rate
     * @param string $key
     * @return string
     */
    private function formatColumnValue(Rate $rate, string $key): string
    {
        $value = match ($key) {
            'country_code'       => $rate->getCountryCode(),
            'region_code'        => $rate->getRegionCode(),
            'city'               => $rate->getCity(),
            'zip_from'           => $rate->getZipFrom(),
            'zip_to'             => $rate->getZipTo(),
            'weight_from'        => $rate->getWeightFrom(),
            'weight_to'          => $rate->getWeightTo(),
            'qty_from'           => $rate->getQtyFrom(),
            'qty_to'             => $rate->getQtyTo(),
            'subtotal_from'      => $rate->getSubtotalFrom(),
            'subtotal_to'        => $rate->getSubtotalTo(),
            'customer_group_ids' => implode(',', $rate->getCustomerGroupIds() ?? []) ?: null,
            'shipping_type'      => $rate->getShippingType(),
            'rate_base'                   => $rate->getRateBase(),
            'rate_per_product'            => $rate->getRatePerProduct(),
            'rate_per_kg'                 => $rate->getRatePerKg(),
            'rate_percent'                => $rate->getRatePercent(),
            'weight_unit_conversion_rate' => $rate->getWeightUnitConversionRate(),
            'delivery_days'               => $rate->getDeliveryDays(),
            'delivery_label'              => $rate->getDeliveryLabel(),
            'name_delivery'               => $rate->getNameDelivery(),
            'comment'            => $rate->getComment(),
            'sort_order'         => $rate->getSortOrder(),
            'is_active'          => $rate->isActive() ? 1 : 0,

            // delete_row is a CSV directive column (Feature 5), not stored
            // on the rate. Always export as 0 — merchants flip individual
            // rows to 1 in their editor when they want to delete via import.
            'delete_row'         => 0,
            default              => null,
        };

        return $value === null ? '' : (string) $value;
    }
}
