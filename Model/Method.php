<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Data model for a shipping-method definition.
 *
 * Maps to a single row in the etechflow_str_method table. Each row carries
 * the carrier-equivalent label, on/off flag, sort order, optional min/max
 * clamps, the multi-shipping-type aggregation mode, and a JSON blob for
 * extensible per-method settings (volumetric-weight config, store-view
 * labels, customer-group restrictions) without schema churn.
 *
 * Typed accessors are provided as a thin layer over getData()/setData() —
 * Magento's AbstractModel still gets you the magic getters but PHPStan can
 * type-check the explicit methods.
 */
class Method extends AbstractModel
{
    /**
     * Initialise the resource model binding.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel\Method::class);
    }

    public function getMethodId(): ?int
    {
        $id = $this->getData('method_id');
        return $id === null ? null : (int) $id;
    }

    public function getCode(): string
    {
        return (string) $this->getData('code');
    }

    public function setCode(string $code): self
    {
        return $this->setData('code', $code);
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    public function setName(string $name): self
    {
        return $this->setData('name', $name);
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData('is_active', $isActive ? 1 : 0);
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData('sort_order');
    }

    public function getMinRate(): ?float
    {
        $value = $this->getData('min_rate');
        return $value === null ? null : (float) $value;
    }

    public function getMaxRate(): ?float
    {
        $value = $this->getData('max_rate');
        return $value === null ? null : (float) $value;
    }

    /**
     * 'sum' | 'min' | 'max' — see RateCalculator::aggregate().
     */
    public function getMultiTypeMode(): string
    {
        $mode = (string) $this->getData('multi_type_mode');
        return $mode !== '' ? $mode : 'sum';
    }

    public function isFreeShippingCompatible(): bool
    {
        // Default true if the column is null — older rows pre-feature stay compatible
        $value = $this->getData('free_shipping_compatible');
        if ($value === null) {
            return true;
        }
        return (bool) $value;
    }

    /**
     * When TRUE, the cart subtotal used by RateMatcher (for subtotal_from/_to
     * filter) AND by RateCalculator (for the rate_percent term) is the
     * POST-discount subtotal. When FALSE (default), uses pre-discount.
     * Mirrors Amasty's per-method "Use price after discount" flag.
     */
    public function getUsePriceAfterDiscount(): bool
    {
        // Schema default is FALSE — older rows that pre-date this column
        // also resolve to FALSE (Amasty's default), so the matcher's
        // behaviour is unchanged for existing methods.
        return (bool) $this->getData('use_price_after_discount');
    }

    /**
     * When TRUE, the cart subtotal used by this method includes tax.
     * When FALSE (default), uses the pre-tax amount. Combines with
     * `use_price_after_discount` for the four subtotal modes Amasty
     * exposes.
     */
    public function getUsePriceIncludingTax(): bool
    {
        return (bool) $this->getData('use_price_including_tax');
    }

    /**
     * Whether this method computes a "chargeable weight" using volumetric
     * weight (Feature 7 / Amasty parity). When TRUE, RateMatcher uses
     * `max(cart_weight, volumetric_cm3 / volumetric_divisor)` for the
     * weight-range filter AND the per-kg formula term. When FALSE
     * (default), cart_weight is used unchanged — back-compat behaviour.
     */
    public function getUseVolumetricWeight(): bool
    {
        return (bool) $this->getData('use_volumetric_weight');
    }

    /**
     * Volumetric-weight divisor in cm³ per kg. Common courier values:
     * DHL/FedEx air = 5000, FedEx ground = 6000, Royal Mail Tracked = 5000.
     * NULL or invalid in the DB resolves to the carrier-default 5000.0
     * so a forgotten / corrupted column doesn't divide-by-zero the formula.
     * Only consulted when getUseVolumetricWeight() is TRUE.
     */
    public function getVolumetricDivisor(): float
    {
        $raw = $this->getData('volumetric_divisor');
        if ($raw === null || $raw === '') {
            return 5000.0;
        }
        $value = (float) $raw;
        return $value > 0.0 ? $value : 5000.0;
    }

    /**
     * Method-level store-view scope (Feature 6 / Amasty parity).
     * Returns the list of Magento store IDs this method applies to,
     * or NULL when the method applies to ALL store views.
     *
     * Stored as a comma-separated string; non-numeric / negative
     * entries are silently dropped.
     *
     * @return int[]|null
     */
    public function getStoreViewIds(): ?array
    {
        return $this->parseIdCsv($this->getData('store_view_ids'));
    }

    /**
     * Method-level customer-group scope (Feature 6 / Amasty parity).
     * Distinct from the per-rate customer_group_id filter — this is
     * evaluated BEFORE any rates are loaded, so a method that doesn't
     * apply to the cart's group is skipped entirely.
     *
     * @return int[]|null  NULL = applies to all groups
     */
    public function getCustomerGroupIds(): ?array
    {
        return $this->parseIdCsv($this->getData('customer_group_ids'));
    }

    /**
     * Parse a comma-separated ID list to an int[] or NULL when the column
     * is empty (= "applies to all"). Shared between getStoreViewIds and
     * getCustomerGroupIds since they have identical normalisation.
     *
     * @param mixed $raw
     * @return int[]|null
     */
    private function parseIdCsv(mixed $raw): ?array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        $ids = [];
        foreach (explode(',', (string) $raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && ctype_digit(ltrim($piece, '-')) && (int) $piece >= 0) {
                $ids[(int) $piece] = true;
            }
        }
        return empty($ids) ? null : array_keys($ids);
    }

    /**
     * Shipping-type values whose per-group cost contribution should be
     * forced to zero by this method (Feature 4 / Amasty parity:
     * "Ship These Shipping Types for Free"). Stored in the DB as a
     * comma-separated string; this getter returns the parsed list
     * with each entry lowercased + trimmed + de-duplicated.
     *
     * NULL or empty column → [] (no overrides). Wildcard rates
     * (NULL shipping_type) are NEVER zeroed by this list — they're
     * the method's cart-level fallback and conceptually orthogonal
     * to per-type freebies.
     *
     * @return string[]
     */
    public function getShipForFreeTypes(): array
    {
        $raw = (string) $this->getData('ship_for_free_types');
        if (trim($raw) === '') {
            return [];
        }
        $types = [];
        foreach (explode(',', $raw) as $piece) {
            $normalised = strtolower(trim($piece));
            if ($normalised !== '') {
                $types[$normalised] = true;
            }
        }
        return array_keys($types);
    }

    /**
     * Return the JSON-decoded per-method settings, defaulting to an empty array
     * if the column is null or unparseable.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $raw = (string) $this->getData('settings_json');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
