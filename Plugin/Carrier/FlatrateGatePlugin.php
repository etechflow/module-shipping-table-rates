<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Plugin\Carrier;

use ETechFlow\ShippingTableRates\Model\Carrier\TableRates;
use ETechFlow\ShippingTableRates\Model\Config as StrConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Smart-fallback gate for Magento's built-in flatrate carrier.
 *
 * Looks at the merchant's chosen Flat Rate Behavior config flag and:
 *
 *   always_show   - never intercepts; Flat Rate runs as Magento normally would
 *   never_show    - suppresses Flat Rate whenever STR is enabled (returns false)
 *   smart_fallback - default; suppresses Flat Rate ONLY if STR's carrier
 *                    actually returned at least one matching rate for THIS cart.
 *                    If STR has nothing for the cart (e.g. customer in an
 *                    unsupported country), Flat Rate runs as a safety net so
 *                    the customer can still check out.
 *
 * This is registered against \Magento\OfflineShipping\Model\Carrier\Flatrate
 * in etc/di.xml (global scope so storefront checkout sees it).
 */
class FlatrateGatePlugin
{
    private const XML_PATH_BEHAVIOR = 'etechflow_shippingtablerates/general/flatrate_behavior';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StrConfig $strConfig,
        private readonly TableRates $strCarrier
    ) {
    }

    /**
     * @param \Magento\OfflineShipping\Model\Carrier\Flatrate $subject
     * @param callable $proceed
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|bool|null
     */
    public function aroundCollectRates($subject, callable $proceed, RateRequest $request)
    {
        $behavior = (string) $this->scopeConfig->getValue(self::XML_PATH_BEHAVIOR);
        if ($behavior === '') {
            $behavior = 'smart_fallback';
        }

        // Mode A — always show Flat Rate alongside STR. No interception.
        if ($behavior === 'always_show') {
            return $proceed($request);
        }

        // If STR itself is disabled/unlicensed, Flat Rate should NOT be hidden -
        // otherwise the merchant has zero shipping options and checkout breaks.
        if (!$this->strConfig->isEnabled()) {
            return $proceed($request);
        }

        // Mode B — never show Flat Rate while STR is enabled.
        if ($behavior === 'never_show') {
            return false;
        }

        // Mode C — smart fallback (default).
        // Ask STR's carrier whether it would return any rate for this cart.
        // If yes, suppress Flat Rate; if no, let it through as the safety net.
        try {
            $strResult = $this->strCarrier->collectRates($request);
        } catch (\Throwable) {
            return $proceed($request); // STR crashed - keep Flat Rate visible
        }
        if ($strResult && method_exists($strResult, 'getAllRates') && !empty($strResult->getAllRates())) {
            return false;
        }
        return $proceed($request);
    }
}
