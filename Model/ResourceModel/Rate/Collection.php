<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\ResourceModel\Rate;

use ETechFlow\ShippingTableRates\Model\Rate;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate as RateResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of Rate rows.
 *
 * Provides scoped helpers for the RateMatcher's hot path so the DB does as
 * much filtering as possible before we apply per-row condition logic in PHP.
 * The composite indexes declared in db_schema.xml back these.
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Rate::class, RateResource::class);
    }

    /**
     * Restrict to active rates for a specific method.
     *
     * @param int $methodId
     * @return $this
     */
    public function addMethodFilter(int $methodId): self
    {
        $this->addFieldToFilter('method_id', $methodId);
        $this->addFieldToFilter('is_active', 1);
        return $this;
    }

    /**
     * SQL-level pre-filter for the country condition.
     *
     * A rate matches when its country_code IS NULL (wildcard) OR matches
     * the cart's country. Uses Zend_Db_Expr-style array filter so the
     * NULL branch hits the index.
     *
     * @param string $countryCode ISO 3166-1 alpha-2
     * @return $this
     */
    public function addCountryFilter(string $countryCode): self
    {
        $this->addFieldToFilter(
            'country_code',
            [
                ['null' => true],
                ['eq'   => $countryCode],
            ]
        );
        return $this;
    }

    /**
     * Order by sort_order ascending, then rate_id ascending — deterministic
     * tie-break so a merchant who saves two equal-priority rules always
     * gets the same winner (and the conflict detector can flag it).
     *
     * @return $this
     */
    public function addSortOrderAsc(): self
    {
        $this->addOrder('sort_order', self::SORT_ORDER_ASC);
        $this->addOrder('rate_id', self::SORT_ORDER_ASC);
        return $this;
    }
}
