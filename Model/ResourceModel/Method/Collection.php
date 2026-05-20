<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\ResourceModel\Method;

use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of Method models for the methods grid + checkout-time lookups.
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Method::class, MethodResource::class);
    }

    /**
     * Filter to active methods only — used at checkout to avoid loading
     * paused/draft method rows.
     *
     * @return $this
     */
    public function addActiveFilter(): self
    {
        $this->addFieldToFilter('is_active', 1);
        return $this;
    }
}
