<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

/**
 * Immutable snapshot of the cart state used to look up matching rates.
 *
 * Built by CartContextBuilder from a Magento Quote\Address, then fed into
 * RateMatcher. Decoupling the snapshot from Magento's quote model lets us:
 *
 *  - Unit-test the matcher without instantiating any Magento classes
 *  - Re-use the matcher in the admin "live cart simulator" (Phase 5) where
 *    the merchant types hypothetical cart parameters that aren't backed by
 *    a real Magento Quote
 *  - Re-use it in the CLI verify command without booting a Magento area
 *
 * All fields are intentionally simple scalars / scalar arrays — no Magento
 * model references — so the object is JSON-serialisable for transport
 * between admin AJAX requests in Phase 5.
 */
class CartContext
{
    /**
     * Constructor — readonly fields, build once via CartContextBuilder.
     *
     * Four subtotal variants are carried so the matcher can pick the right
     * one per method (Feature 3 / Amasty parity: "Use price after discount"
     * + "Use price including tax" are per-method toggles). The canonical
     * `$subtotal` field is still resolved by `Config::useDiscountedPrice()`
     * for back-compat with callers that read it directly. New callers
     * should prefer `subtotalForMethod($method)`.
     *
     * @param string      $countryCode ISO 3166-1 alpha-2 ("GB", "US", "DE", ""=unknown)
     * @param string      $regionCode  State/region code or name ("CA", "NY", "ENG", "") — caller may normalise
     * @param string      $city        City name ("London", "New York", "") — caller-supplied
     * @param string      $postcode    Postal code as-typed ("SW1A 1AA", "10001", "") — alphanumeric
     * @param float       $weight      Total cart weight in store unit (kg or lb)
     * @param int         $qty         Total item count in cart (sum of line qty)
     * @param float       $subtotal    Canonical subtotal — discounted or not depending on Config::useDiscountedPrice (back-compat field)
     * @param int         $customerGroupId Magento customer group id (0 = NOT LOGGED IN, 1 = General, etc.)
     * @param string[]    $shippingTypes Distinct shipping_type values present in the cart (lowercased)
     * @param float|null  $subtotalAfterDiscount          Pre-tax, post-discount. NULL = same as $subtotal (older test fixtures stay valid).
     * @param float|null  $subtotalInclTax                Incl-tax, pre-discount. NULL = same as $subtotal.
     * @param float|null  $subtotalInclTaxAfterDiscount   Incl-tax, post-discount. NULL = same as $subtotal.
     * @param int         $storeId                        Magento store-view id (Feature 6 method-level scoping). 0 = unknown / admin context.
     * @param float       $volumetricCm3                  Total cart cm³ from product dimensions × qty (Feature 7). 0.0 = no dimension data — methods with use_volumetric_weight=YES then fall back to actual cart_weight.
     */
    public function __construct(
        public readonly string $countryCode,
        public readonly string $regionCode,
        public readonly string $city,
        public readonly string $postcode,
        public readonly float $weight,
        public readonly int $qty,
        public readonly float $subtotal,
        public readonly int $customerGroupId,
        public readonly array $shippingTypes,
        public readonly ?float $subtotalAfterDiscount = null,
        public readonly ?float $subtotalInclTax = null,
        public readonly ?float $subtotalInclTaxAfterDiscount = null,
        public readonly int $storeId = 0,
        public readonly float $volumetricCm3 = 0.0
    ) {
    }

    /**
     * The "chargeable weight" for a given method (Feature 7). When the
     * method opts INTO volumetric pricing, this is max(actual cart
     * weight, volumetricCm3 / divisor). Otherwise it's the actual cart
     * weight unchanged.
     *
     * Couriers bill on whichever is greater — actual or volumetric — so
     * the merchant sees a non-rip-off rate when their cart is mostly
     * pillows, and the courier-style margin when the cart is bricks.
     *
     * Methods without dimension data set on any cart item produce a 0
     * volumetric weight, which max() correctly falls back to the actual
     * weight — so a method with use_volumetric_weight=YES is safe even
     * for products that haven't had their dimensions filled in yet.
     *
     * @param Method $method
     * @return float
     */
    public function chargeableWeightForMethod(Method $method): float
    {
        if (!$method->getUseVolumetricWeight()) {
            return $this->weight;
        }
        $divisor = $method->getVolumetricDivisor();  // > 0 guaranteed by getter
        $volumetricWeight = $this->volumetricCm3 / $divisor;
        return max($this->weight, $volumetricWeight);
    }

    /**
     * Pick the subtotal variant that applies to the given method based on
     * its `use_price_after_discount` and `use_price_including_tax` flags.
     * Used by both the subtotal-range filter in RateMatcher and the
     * rate_percent term in RateCalculator.
     *
     * Falls back to `$subtotal` when a variant is null (older tests that
     * construct CartContext directly without populating the variants;
     * also future-proofs against Magento not exposing a particular
     * combination on a given store config).
     *
     * @param Method $method
     * @return float
     */
    public function subtotalForMethod(Method $method): float
    {
        $afterDiscount = $method->getUsePriceAfterDiscount();
        $inclTax       = $method->getUsePriceIncludingTax();
        return match (true) {
            $inclTax && $afterDiscount   => $this->subtotalInclTaxAfterDiscount ?? $this->subtotal,
            $inclTax                     => $this->subtotalInclTax ?? $this->subtotal,
            $afterDiscount               => $this->subtotalAfterDiscount ?? $this->subtotal,
            default                      => $this->subtotal,
        };
    }

    /**
     * Whether the cart contains at least one item with the given shipping_type.
     * Case-insensitive — both sides are lowercased.
     *
     * @param string $type
     * @return bool
     */
    public function hasShippingType(string $type): bool
    {
        $needle = strtolower(trim($type));
        if ($needle === '') {
            return false;
        }
        foreach ($this->shippingTypes as $present) {
            if (strtolower(trim($present)) === $needle) {
                return true;
            }
        }
        return false;
    }
}
