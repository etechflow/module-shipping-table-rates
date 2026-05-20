<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Data model for a single rate rule.
 *
 * Maps to a row in etechflow_str_rate. Every cart-condition column is
 * nullable on purpose: NULL = "match any value" for that condition. The
 * rate matches a CartContext when EVERY non-null condition matches.
 *
 * Typed accessors (returning nullable scalars for nullable columns) make
 * the matcher's logic straightforward — no need to remember which
 * field-name strings to pull and cast in every comparison.
 */
class Rate extends AbstractModel
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel\Rate::class);
    }

    public function getRateId(): ?int
    {
        $id = $this->getData('rate_id');
        return $id === null ? null : (int) $id;
    }

    public function getMethodId(): int
    {
        return (int) $this->getData('method_id');
    }

    public function setMethodId(int $methodId): self
    {
        return $this->setData('method_id', $methodId);
    }

    // --- Geographic conditions (all nullable: NULL = match any) ---

    public function getCountryCode(): ?string
    {
        $value = $this->getData('country_code');
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function getRegionCode(): ?string
    {
        $value = $this->getData('region_code');
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function getCity(): ?string
    {
        $value = $this->getData('city');
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function getZipFrom(): ?string
    {
        $value = $this->getData('zip_from');
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function getZipTo(): ?string
    {
        $value = $this->getData('zip_to');
        return $value === null || $value === '' ? null : (string) $value;
    }

    // --- Cart conditions (numeric ranges, all nullable) ---

    public function getWeightFrom(): ?float
    {
        $value = $this->getData('weight_from');
        return $value === null ? null : (float) $value;
    }

    public function getWeightTo(): ?float
    {
        $value = $this->getData('weight_to');
        return $value === null ? null : (float) $value;
    }

    public function getQtyFrom(): ?int
    {
        $value = $this->getData('qty_from');
        return $value === null ? null : (int) $value;
    }

    public function getQtyTo(): ?int
    {
        $value = $this->getData('qty_to');
        return $value === null ? null : (int) $value;
    }

    public function getSubtotalFrom(): ?float
    {
        $value = $this->getData('subtotal_from');
        return $value === null ? null : (float) $value;
    }

    public function getSubtotalTo(): ?float
    {
        $value = $this->getData('subtotal_to');
        return $value === null ? null : (float) $value;
    }

    /**
     * Comma-separated customer group IDs, or NULL = match all.
     * Stored as a string in a single column for simplicity in v0.x.
     *
     * @return int[]|null  Returns null when the column is null/empty (match all),
     *                     otherwise an array of integer group IDs.
     */
    public function getCustomerGroupIds(): ?array
    {
        $value = $this->getData('customer_group_id');
        if ($value === null || $value === '') {
            return null;
        }
        $ids = array_filter(
            array_map('intval', explode(',', (string) $value)),
            static fn($id) => $id >= 0
        );
        return empty($ids) ? null : array_values($ids);
    }

    public function getShippingType(): ?string
    {
        $value = $this->getData('shipping_type');
        return $value === null || $value === '' ? null : strtolower(trim((string) $value));
    }

    // --- Rate formula components (default 0.0 so calculator can add freely) ---

    public function getRateBase(): float
    {
        return (float) $this->getData('rate_base');
    }

    public function getRatePerProduct(): float
    {
        return (float) $this->getData('rate_per_product');
    }

    public function getRatePerKg(): float
    {
        return (float) $this->getData('rate_per_kg');
    }

    public function getRatePercent(): float
    {
        return (float) $this->getData('rate_percent');
    }

    /**
     * Per-rate weight unit conversion factor. The matcher DIVIDES cart weight
     * by this value before applying the per-kg term of the formula. Defaults
     * to 1.0 (no conversion). Treats 0 or negative inputs as 1.0 so a bad
     * import row can't divide-by-zero the formula.
     *
     * Mirrors Amasty's "Weight Unit Conversion Rate" field — same semantics,
     * same example values (2.2046 for lbs→kg, 0.4536 for kg→lbs).
     */
    public function getWeightUnitConversionRate(): float
    {
        $value = (float) $this->getData('weight_unit_conversion_rate');
        return $value > 0.0 ? $value : 1.0;
    }

    public function getDeliveryDays(): ?int
    {
        $value = $this->getData('delivery_days');
        return $value === null ? null : (int) $value;
    }

    /**
     * Free-text label that substitutes the `{day}` placeholder in the parent
     * method's name (Amasty parity feature). NULL when not configured —
     * MatchResult falls back to the integer delivery_days in that case.
     */
    public function getDeliveryLabel(): ?string
    {
        $value = $this->getData('delivery_label');
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Free-text label that substitutes the `{name}` placeholder in the parent
     * method's name. NULL when not configured.
     */
    public function getNameDelivery(): ?string
    {
        $value = $this->getData('name_delivery');
        return $value === null || $value === '' ? null : (string) $value;
    }

    public function getComment(): string
    {
        return (string) $this->getData('comment');
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData('sort_order');
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }
}
