<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Three-way source for the "Flat Rate Behavior" select under
 * Stores -> Configuration -> ETECHFLOW -> Shipping Table Rates ->
 * General Settings. Wired to the FlatrateGatePlugin at runtime.
 */
class FlatrateBehavior implements ArrayInterface
{
    public const SMART_FALLBACK = 'smart_fallback';
    public const NEVER_SHOW     = 'never_show';
    public const ALWAYS_SHOW    = 'always_show';

    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::SMART_FALLBACK,
                'label' => __('Smart fallback — show Flat Rate ONLY when STR has no matching rule (recommended)'),
            ],
            [
                'value' => self::NEVER_SHOW,
                'label' => __('Never show Flat Rate when STR is enabled'),
            ],
            [
                'value' => self::ALWAYS_SHOW,
                'label' => __('Always show Flat Rate alongside STR (Magento default)'),
            ],
        ];
    }
}
