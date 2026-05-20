<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Module-level config accessor for ETechFlow_ShippingTableRates.
 *
 * Stays narrow on purpose — per-method settings live on the method record
 * itself (etechflow_str_method table); this class only handles the
 * module-wide kill-switch and a few cross-cutting toggles.
 */
class Config
{
    private const XML_PATH_ENABLED                 = 'etechflow_shippingtablerates/general/enabled';
    private const XML_PATH_USE_DISCOUNTED_PRICE    = 'etechflow_shippingtablerates/general/use_discounted_price';
    private const XML_PATH_PRICE_INCLUDES_TAX      = 'etechflow_shippingtablerates/general/price_includes_tax';
    private const XML_PATH_CONFLICT_DETECTION      = 'etechflow_shippingtablerates/general/conflict_detection';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param LicenseValidator     $licenseValidator
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    /**
     * Master kill-switch. Returns false when licence is invalid OR admin
     * has explicitly toggled Enable Module to No.
     *
     * The licence check fires FIRST so an unlicensed install silently
     * no-ops at checkout instead of crashing.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        if (!$this->licenseValidator->isValid()) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Should rate calculations use the discounted subtotal (after coupon/promo)
     * or the original subtotal?
     *
     * Default: discounted (matches Magento's native behaviour). Amasty makes
     * this a per-method choice — we keep it module-wide for simplicity and
     * may promote it to per-method if customer feedback asks.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function useDiscountedPrice(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_USE_DISCOUNTED_PRICE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === null || $value === '') {
            return true;
        }
        return (bool) $value;
    }

    /**
     * Are configured rate amounts inclusive of tax? Affects how Magento
     * displays and rounds them at checkout.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function priceIncludesTax(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PRICE_INCLUDES_TAX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether to run the admin-side rule-conflict detector. One of our v1.0
     * differentiators — surfaces overlapping rules so they don't bite a
     * customer at checkout.
     *
     * Default ON; merchants with thousands of rate rules can turn it off
     * to skip the detection cost on every save.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isConflictDetectionEnabled(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CONFLICT_DETECTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === null || $value === '') {
            return true;
        }
        return (bool) $value;
    }
}
