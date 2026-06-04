<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Yellow advisory banner rendered above the Methods grid when
 * Magento's built-in flat-rate carrier is active.
 *
 * The merchant sees ONE click to disable it (so STR's own rules
 * stop competing with the stock $5 Flat Rate at checkout).
 * Auto-hides once Flat Rate is off.
 */
class FlatrateBanner extends Template
{
    public const XML_PATH_FLATRATE_ACTIVE = 'carriers/flatrate/active';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Only render when Magento's flatrate carrier is enabled.
     */
    public function shouldShow(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::XML_PATH_FLATRATE_ACTIVE);
    }

    /**
     * One-click "Disable Flat Rate" controller endpoint.
     */
    public function getDisableUrl(): string
    {
        return $this->getUrl('etechflow_str/tools/disableFlatrate');
    }

    /**
     * Direct link to the merchant's three-way fallback choice
     * under Stores -> Config -> ETECHFLOW -> Shipping Table Rates ->
     * General Settings -> "Flat Rate Behavior".
     */
    public function getBehaviorConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', [
            'section' => 'etechflow_shippingtablerates',
            '_fragment' => 'etechflow_shippingtablerates_general-link'
        ]);
    }

    /**
     * Current behavior choice, for showing the merchant what mode
     * they're already in (smart fallback by default).
     */
    public function getCurrentBehavior(): string
    {
        $value = (string) $this->scopeConfig->getValue('etechflow_shippingtablerates/general/flatrate_behavior');
        return $value !== '' ? $value : 'smart_fallback';
    }
}
