<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

use ETechFlow\ShippingTableRates\Model\Performance\Profiler;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Finds the matching rate(s) for a Method given a CartContext, and returns
 * the total shipping cost.
 *
 * The core algorithm in 5 steps:
 *
 *  1. Pull every active rate for the method, pre-filtered by country at
 *     SQL level. Order by sort_order ASC, rate_id ASC (deterministic).
 *
 *  2. For each remaining rate, check every non-null condition against the
 *     context: region / city / zip-range / weight-range / qty-range /
 *     subtotal-range / customer-group / shipping-type. A rate is a
 *     "candidate" only if EVERY condition matches.
 *
 *  3. Group candidate rates by shipping_type — rates with NULL shipping_type
 *     are a wildcard bucket (always apply); rates targeting a specific
 *     shipping_type apply only when the cart carries an item of that type.
 *
 *  4. From each shipping_type group, pick the WINNER (first by sort_order,
 *     ties broken by rate_id) and compute its cost via RateCalculator.
 *
 *  5. Aggregate the per-type costs per the method's multi_type_mode
 *     ('sum'|'min'|'max') and clamp by the method's min/max.
 *
 * Returns null when no rate matches — the carrier should then NOT offer
 * the method at checkout.
 */
class RateMatcher
{
    /**
     * Sentinel key used for rates with NULL shipping_type — the "applies to
     * the whole cart regardless of product types" bucket.
     */
    private const WILDCARD_TYPE_KEY = '__wildcard__';

    /**
     * Constructor.
     *
     * @param RateCollectionFactory $rateCollectionFactory
     * @param RateCalculator        $calculator
     * @param LoggerInterface       $logger
     */
    public function __construct(
        private readonly RateCollectionFactory $rateCollectionFactory,
        private readonly RateCalculator $calculator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Find the matching rate(s) for the given method + cart, and return the
     * total shipping cost. Returns null if no rate matches (caller should
     * NOT offer this method).
     *
     * Wrapped in try/catch so a DB hiccup or malformed rate row can never
     * crash checkout — logs the error and returns null instead.
     *
     * @param Method      $method
     * @param CartContext $context
     * @return MatchResult|null
     */
    public function match(Method $method, CartContext $context): ?MatchResult
    {
        $span = Profiler::start('ETechFlow_STR_RateMatcher_match');
        try {
            $methodId = $method->getMethodId();
            if ($methodId === null) {
                return null;
            }

            // Feature 6: method-level scope filter (Amasty parity). A method
            // with explicit store_view_ids / customer_group_ids applies ONLY
            // to those scopes; NULL on either column means "applies to all".
            // We short-circuit here so an out-of-scope method doesn't even
            // load its rate collection.
            $methodStoreIds = $method->getStoreViewIds();
            if ($methodStoreIds !== null && !in_array($context->storeId, $methodStoreIds, true)) {
                return null;
            }
            $methodGroupIds = $method->getCustomerGroupIds();
            if ($methodGroupIds !== null && !in_array($context->customerGroupId, $methodGroupIds, true)) {
                return null;
            }

            // Feature 3: resolve the subtotal variant the method's flags ask
            // for ONCE per match, then thread it through the filter + formula.
            // Default flags (both FALSE) reproduce the pre-Feature-3 behaviour
            // because $context->subtotal is still what subtotalForMethod()
            // returns when the variants are null.
            $methodSubtotal = $context->subtotalForMethod($method);

            // Feature 7: same dance for chargeable weight. Default flag (off)
            // returns $context->weight unchanged; opting in produces
            // max(weight, volumetricCm3 / divisor).
            $methodWeight = $context->chargeableWeightForMethod($method);

            // Step 1: SQL pre-filter
            $collection = $this->rateCollectionFactory->create();
            $collection->addMethodFilter($methodId);
            if ($context->countryCode !== '') {
                $collection->addCountryFilter($context->countryCode);
            }
            $collection->addSortOrderAsc();

            // Step 2: per-row PHP-level condition matching
            $candidates = [];
            foreach ($collection as $rate) {
                if ($this->matchesAllNonShippingTypeConditions($rate, $context, $methodSubtotal, $methodWeight)) {
                    $candidates[] = $rate;
                }
            }

            if (empty($candidates)) {
                return null;
            }

            // Step 3: group by shipping_type
            $groups = $this->groupByShippingType($candidates, $context);
            if (empty($groups)) {
                return null;
            }

            // Step 4: winner per group + per-rate cost. Feature 4 zeros out
            // any group whose shipping_type appears in the method's
            // ship_for_free_types list — the winning rate is still recorded
            // (so MatchResult / admin debug shows what would have matched)
            // but its cost contribution is 0. Wildcard rates (NULL
            // shipping_type) are NEVER zeroed: they're the cart-level
            // fallback and conceptually orthogonal to per-type freebies.
            $shipForFreeTypes = $method->getShipForFreeTypes();
            $costs = [];
            $winners = [];
            foreach ($groups as $type => $groupRates) {
                $winner = $groupRates[0];  // collection is already sorted by sort_order ASC
                $winners[] = $winner;
                if ($type !== self::WILDCARD_TYPE_KEY && in_array($type, $shipForFreeTypes, true)) {
                    $costs[] = 0.0;
                    continue;
                }
                $costs[] = $this->calculator->calculate(
                    $context,
                    $winner->getRateBase(),
                    $winner->getRatePerProduct(),
                    $winner->getRatePerKg(),
                    $winner->getRatePercent(),
                    null,
                    null,
                    $winner->getWeightUnitConversionRate(),
                    $methodSubtotal,
                    $methodWeight
                );
            }

            // Step 5: aggregate per method's multi-type mode, then method-level clamp
            $aggregated = $this->calculator->aggregate($costs, $method->getMultiTypeMode());

            $minRate = $method->getMinRate();
            $maxRate = $method->getMaxRate();
            if ($minRate !== null && $aggregated < $minRate) {
                $aggregated = $minRate;
            }
            if ($maxRate !== null && $aggregated > $maxRate) {
                $aggregated = $maxRate;
            }
            $aggregated = max(0.0, $aggregated);

            return new MatchResult($winners, $aggregated);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_ShippingTableRates: RateMatcher failed; method will not be offered.',
                [
                    'method_id' => $method->getMethodId(),
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * Check every condition EXCEPT shipping_type. Shipping-type matching is
     * handled at the grouping step because it interacts with multi-type
     * aggregation.
     *
     * @param Rate        $rate
     * @param CartContext $context
     * @param float       $methodSubtotal The per-method-resolved subtotal (Feature 3) used for the subtotal-range filter
     * @param float       $methodWeight   The per-method-resolved chargeable weight (Feature 7) used for the weight-range filter
     * @return bool
     */
    private function matchesAllNonShippingTypeConditions(Rate $rate, CartContext $context, float $methodSubtotal, float $methodWeight): bool
    {
        // Country — already SQL-filtered for non-null cart country, but a rate
        // with country_code=US still needs to match a cart in US (the SQL
        // filter let through wildcards AND matches, so we re-confirm)
        $rateCountry = $rate->getCountryCode();
        if ($rateCountry !== null && strcasecmp($rateCountry, $context->countryCode) !== 0) {
            return false;
        }

        // Region (state) — case-insensitive
        $rateRegion = $rate->getRegionCode();
        if ($rateRegion !== null && strcasecmp($rateRegion, $context->regionCode) !== 0) {
            return false;
        }

        // City — case-insensitive
        $rateCity = $rate->getCity();
        if ($rateCity !== null && strcasecmp($rateCity, $context->city) !== 0) {
            return false;
        }

        // Postcode range — alphanumeric-safe via lexicographic comparison
        // after normalisation (strip spaces, uppercase). For purely-numeric
        // postcodes lexicographic comparison gives correct order for
        // same-length codes (10001 < 10002), which is the typical case.
        // UK postcodes ("SW1A 1AA") are compared after space-strip; same-prefix
        // ordering holds (SW1A1AA < SW1A1AB) which is what merchants want.
        if (!$this->matchesPostcodeRange($rate, $context)) {
            return false;
        }

        // Weight range — uses the per-method-resolved chargeable weight
        // (Feature 7) so methods opting INTO volumetric pricing see the
        // larger of (actual cart weight, volumetric weight) at the filter.
        if (!$this->matchesNumericRange(
            $methodWeight,
            $rate->getWeightFrom(),
            $rate->getWeightTo()
        )) {
            return false;
        }

        // Qty range
        if (!$this->matchesNumericRange(
            (float) $context->qty,
            $rate->getQtyFrom() !== null ? (float) $rate->getQtyFrom() : null,
            $rate->getQtyTo() !== null ? (float) $rate->getQtyTo() : null
        )) {
            return false;
        }

        // Subtotal range — uses the per-method-resolved subtotal so methods
        // with use_price_after_discount / use_price_including_tax flags see
        // the right subtotal at the filter level (not just in the formula).
        if (!$this->matchesNumericRange(
            $methodSubtotal,
            $rate->getSubtotalFrom(),
            $rate->getSubtotalTo()
        )) {
            return false;
        }

        // Customer group — NULL = match all, otherwise list must contain cart's group
        $rateGroups = $rate->getCustomerGroupIds();
        if ($rateGroups !== null && !in_array($context->customerGroupId, $rateGroups, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether the cart's postcode falls inside the rate's [zip_from, zip_to]
     * range. NULL bounds mean "no constraint on that side".
     *
     * Normalises both sides: strip spaces + uppercase. Lexicographic compare.
     *
     * @param Rate        $rate
     * @param CartContext $context
     * @return bool
     */
    private function matchesPostcodeRange(Rate $rate, CartContext $context): bool
    {
        $from = $rate->getZipFrom();
        $to   = $rate->getZipTo();

        if ($from === null && $to === null) {
            return true;
        }

        $normalise = static fn(string $s) => strtoupper(str_replace(' ', '', trim($s)));
        $cart      = $normalise($context->postcode);

        if ($cart === '') {
            // Rate has a postcode constraint but cart has no postcode yet
            // (early-checkout step) — reject so we don't quote prematurely
            return false;
        }

        if ($from !== null && strcmp($cart, $normalise($from)) < 0) {
            return false;
        }
        if ($to !== null && strcmp($cart, $normalise($to)) > 0) {
            return false;
        }
        return true;
    }

    /**
     * Inclusive numeric range check with optional bounds.
     *
     * @param float      $value
     * @param float|null $from
     * @param float|null $to
     * @return bool
     */
    private function matchesNumericRange(float $value, ?float $from, ?float $to): bool
    {
        if ($from !== null && $value < $from) {
            return false;
        }
        if ($to !== null && $value > $to) {
            return false;
        }
        return true;
    }

    /**
     * Group candidate rates by shipping_type, keeping only types relevant to
     * the cart. NULL-shipping_type rates form the wildcard group (always
     * applies). Type-specific rates form their own group only if a cart item
     * matches that type.
     *
     * @param Rate[]      $candidates
     * @param CartContext $context
     * @return array<string, Rate[]>  Keyed by shipping_type (or WILDCARD_TYPE_KEY)
     */
    private function groupByShippingType(array $candidates, CartContext $context): array
    {
        $groups = [];
        foreach ($candidates as $rate) {
            $type = $rate->getShippingType();
            if ($type === null) {
                $groups[self::WILDCARD_TYPE_KEY][] = $rate;
                continue;
            }
            if ($context->hasShippingType($type)) {
                $groups[$type][] = $rate;
            }
            // else: rate targets a shipping_type not present in the cart — skip
        }
        return $groups;
    }
}
