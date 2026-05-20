<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method\Edit;

use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * Renders the rates table under the method edit form.
 *
 * For each rate row in the method: shows the most-used condition fields
 * (country / region / postcode-range / weight-range / qty-range / shipping
 * type) + the rate components + per-row Edit/Delete buttons. Add/Edit
 * routes to a dedicated rate form page (separate add/edit pages keep the
 * UI simple and the edit-page-per-method scrollable; full inline-AJAX
 * editor was considered for v1.0 but deferred to v1.x based on customer
 * feedback).
 *
 * CSV import remains the primary bulk-edit path; this is the manual /
 * one-off path.
 */
class Rates extends Template
{
    private const MAX_ROWS_VISIBLE = 50;

    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly RateCollectionFactory $rateCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return int|null
     */
    public function getMethodId(): ?int
    {
        $method = $this->coreRegistry->registry('etechflow_str_method');
        return $method ? $method->getMethodId() : null;
    }

    /**
     * @return \ETechFlow\ShippingTableRates\Model\Rate[]
     */
    public function getRates(): array
    {
        $methodId = $this->getMethodId();
        if (!$methodId) {
            return [];
        }
        $collection = $this->rateCollectionFactory->create();
        $collection->addFieldToFilter('method_id', $methodId);
        $collection->addOrder('sort_order', 'ASC');
        $collection->addOrder('rate_id', 'ASC');
        $collection->setPageSize(self::MAX_ROWS_VISIBLE);
        /** @var \ETechFlow\ShippingTableRates\Model\Rate[] $items */
        $items = $collection->getItems();
        return $items;
    }

    /**
     * @return int
     */
    public function getTotalRateCount(): int
    {
        $methodId = $this->getMethodId();
        if (!$methodId) {
            return 0;
        }
        $collection = $this->rateCollectionFactory->create();
        $collection->addFieldToFilter('method_id', $methodId);
        return $collection->getSize();
    }

    /**
     * @return string
     */
    public function getAddUrl(): string
    {
        return $this->getUrl('etechflow_str/rate/new', ['method_id' => $this->getMethodId()]);
    }

    /**
     * @param int $rateId
     * @return string
     */
    public function getEditUrl(int $rateId): string
    {
        return $this->getUrl('etechflow_str/rate/edit', ['rate_id' => $rateId]);
    }

    /**
     * @param int $rateId
     * @return string
     */
    public function getDeleteUrl(int $rateId): string
    {
        return $this->getUrl('etechflow_str/rate/delete', ['rate_id' => $rateId]);
    }

    /**
     * Compact "X / Y - Z" formatter for range fields. Used in the table to
     * keep cells narrow when both sides are populated.
     *
     * @param mixed $from
     * @param mixed $to
     * @param string $emptyLabel
     * @return string
     */
    public function formatRange($from, $to, string $emptyLabel = '*'): string
    {
        if ($from === null && $to === null) {
            return $emptyLabel;
        }
        if ($from !== null && $to !== null) {
            return $from === $to ? (string) $from : sprintf('%s – %s', $from, $to);
        }
        if ($from !== null) {
            return sprintf('≥ %s', $from);
        }
        return sprintf('≤ %s', $to);
    }

    /**
     * Compact rate-formula renderer for the table.
     *
     * @param \ETechFlow\ShippingTableRates\Model\Rate $rate
     * @return string
     */
    public function formatFormula(\ETechFlow\ShippingTableRates\Model\Rate $rate): string
    {
        $parts = [];
        if ($rate->getRateBase() > 0)        { $parts[] = sprintf('%.2f', $rate->getRateBase()); }
        if ($rate->getRatePerProduct() > 0)  { $parts[] = sprintf('+%.2f/item', $rate->getRatePerProduct()); }
        if ($rate->getRatePerKg() > 0)       { $parts[] = sprintf('+%.2f/kg', $rate->getRatePerKg()); }
        if ($rate->getRatePercent() > 0)     { $parts[] = sprintf('+%.2f%%', $rate->getRatePercent()); }
        return $parts ? implode(' ', $parts) : '0';
    }
}
