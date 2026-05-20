<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Conflict;

use ETechFlow\ShippingTableRates\Model\Rate;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;

/**
 * Detects rate rules whose condition surface overlaps with another rule.
 *
 * v1.0 differentiator: Amasty / MageWorx never surface these silently
 * before they bite a customer at checkout. Two rules with identical-or-
 * overlapping conditions and the same sort_order produce non-deterministic
 * picks — a merchant who saves them gets random behaviour they only
 * discover via customer complaints.
 *
 * Returns a list of (other_rate_id, reason) pairs so the admin save flow
 * can surface "this rate overlaps with rate #57 because both target GB
 * weight 5-10kg" as an informational flash message. The merchant decides
 * whether the overlap is intentional (it sometimes is — wildcard fallback
 * + type-specific override is a valid pattern) or a bug to fix.
 *
 * Disabled by Config::isConflictDetectionEnabled() — merchants with
 * thousands of rules can opt out to save the per-save scan cost.
 */
class ConflictDetector
{
    /**
     * Constructor.
     *
     * @param RateCollectionFactory $rateCollectionFactory
     */
    public function __construct(
        private readonly RateCollectionFactory $rateCollectionFactory
    ) {
    }

    /**
     * Find rules in the same method that overlap with the candidate's
     * condition surface. Compares against all OTHER active rules of the
     * same method — never reports a rule conflicting with itself.
     *
     * @param Rate $candidate The rule that was just (about to be) saved.
     * @return array<int, array{rate_id:int, reason:string}>
     *         Empty array = no conflicts.
     */
    public function detect(Rate $candidate): array
    {
        $methodId = $candidate->getMethodId();
        if ($methodId <= 0) {
            return [];
        }

        $collection = $this->rateCollectionFactory->create();
        $collection->addFieldToFilter('method_id', $methodId);
        $collection->addFieldToFilter('is_active', 1);
        if ($candidate->getRateId() !== null) {
            // Exclude self when editing an existing rule
            $collection->addFieldToFilter('rate_id', ['neq' => $candidate->getRateId()]);
        }

        $conflicts = [];
        foreach ($collection as $other) {
            /** @var Rate $other */
            $reason = $this->describeOverlap($candidate, $other);
            if ($reason !== null) {
                $conflicts[] = [
                    'rate_id' => (int) $other->getRateId(),
                    'reason'  => $reason,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Return a human-readable overlap reason if two rules could match the
     * same cart, or null if they're mutually exclusive.
     *
     * Two rules overlap when EVERY condition dimension's ranges either
     * intersect OR one of them is wildcarded. If ANY dimension is mutually
     * exclusive (e.g. rule A is GB-only, rule B is US-only), they can never
     * both fire — no overlap.
     *
     * @param Rate $a
     * @param Rate $b
     * @return string|null
     */
    private function describeOverlap(Rate $a, Rate $b): ?string
    {
        // Geographic — string equality with wildcard semantics
        if (!$this->stringDimensionsOverlap($a->getCountryCode(), $b->getCountryCode())) {
            return null;
        }
        if (!$this->stringDimensionsOverlap($a->getRegionCode(), $b->getRegionCode())) {
            return null;
        }
        if (!$this->stringDimensionsOverlap($a->getCity(), $b->getCity())) {
            return null;
        }

        // Postcode ranges
        if (!$this->postcodeRangesOverlap(
            $a->getZipFrom(), $a->getZipTo(),
            $b->getZipFrom(), $b->getZipTo()
        )) {
            return null;
        }

        // Numeric ranges
        if (!$this->numericRangesOverlap($a->getWeightFrom(), $a->getWeightTo(), $b->getWeightFrom(), $b->getWeightTo())) {
            return null;
        }
        if (!$this->numericRangesOverlap(
            $a->getQtyFrom() !== null ? (float) $a->getQtyFrom() : null,
            $a->getQtyTo()   !== null ? (float) $a->getQtyTo()   : null,
            $b->getQtyFrom() !== null ? (float) $b->getQtyFrom() : null,
            $b->getQtyTo()   !== null ? (float) $b->getQtyTo()   : null
        )) {
            return null;
        }
        if (!$this->numericRangesOverlap($a->getSubtotalFrom(), $a->getSubtotalTo(), $b->getSubtotalFrom(), $b->getSubtotalTo())) {
            return null;
        }

        // Customer groups — NULL = match all; otherwise sets must intersect
        if (!$this->customerGroupsOverlap($a->getCustomerGroupIds(), $b->getCustomerGroupIds())) {
            return null;
        }

        // Shipping type — wildcard semantics (NULL = match any)
        if (!$this->stringDimensionsOverlap($a->getShippingType(), $b->getShippingType())) {
            return null;
        }

        // Every dimension overlaps → these rules can match the same cart
        return $this->buildOverlapReason($a, $b);
    }

    /**
     * Wildcards (null/empty) overlap with anything; otherwise both sides
     * must be the same (case-insensitive).
     */
    private function stringDimensionsOverlap(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }
        return strcasecmp($a, $b) === 0;
    }

    /**
     * Two postcode ranges overlap unless one ends strictly before the other
     * begins (alphanumeric-safe lexicographic compare).
     */
    private function postcodeRangesOverlap(?string $aFrom, ?string $aTo, ?string $bFrom, ?string $bTo): bool
    {
        // Either side fully wildcarded → overlap
        if ($aFrom === null && $aTo === null) {
            return true;
        }
        if ($bFrom === null && $bTo === null) {
            return true;
        }

        $normalise = static fn(?string $s) => $s === null ? null : strtoupper(str_replace(' ', '', $s));
        $aFrom = $normalise($aFrom);
        $aTo   = $normalise($aTo);
        $bFrom = $normalise($bFrom);
        $bTo   = $normalise($bTo);

        // A ends before B begins?
        if ($aTo !== null && $bFrom !== null && strcmp($aTo, $bFrom) < 0) {
            return false;
        }
        // B ends before A begins?
        if ($bTo !== null && $aFrom !== null && strcmp($bTo, $aFrom) < 0) {
            return false;
        }
        return true;
    }

    /**
     * Inclusive numeric ranges with optional bounds.
     */
    private function numericRangesOverlap(?float $aFrom, ?float $aTo, ?float $bFrom, ?float $bTo): bool
    {
        if ($aTo !== null && $bFrom !== null && $aTo < $bFrom) {
            return false;
        }
        if ($bTo !== null && $aFrom !== null && $bTo < $aFrom) {
            return false;
        }
        return true;
    }

    /**
     * NULL = match all groups; non-null sets must share at least one ID.
     */
    private function customerGroupsOverlap(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }
        return !empty(array_intersect($a, $b));
    }

    /**
     * Compose a merchant-readable reason describing why the two rules overlap.
     * Lists the SHARED dimensions so the merchant can see exactly which
     * conditions are the same.
     */
    private function buildOverlapReason(Rate $a, Rate $b): string
    {
        $shared = [];

        if ($a->getCountryCode() !== null && $b->getCountryCode() !== null
            && strcasecmp($a->getCountryCode(), $b->getCountryCode()) === 0) {
            $shared[] = "country={$a->getCountryCode()}";
        }
        if ($a->getRegionCode() !== null && $b->getRegionCode() !== null
            && strcasecmp($a->getRegionCode(), $b->getRegionCode()) === 0) {
            $shared[] = "region={$a->getRegionCode()}";
        }
        if ($a->getShippingType() !== null && $b->getShippingType() !== null
            && strcasecmp($a->getShippingType(), $b->getShippingType()) === 0) {
            $shared[] = "shipping_type={$a->getShippingType()}";
        }
        // Weight / qty / subtotal: report when both have at least one bound set
        foreach (['weight', 'qty', 'subtotal'] as $dim) {
            $aFrom = $a->getData("{$dim}_from");
            $aTo   = $a->getData("{$dim}_to");
            $bFrom = $b->getData("{$dim}_from");
            $bTo   = $b->getData("{$dim}_to");
            if (($aFrom !== null || $aTo !== null) && ($bFrom !== null || $bTo !== null)) {
                $shared[] = sprintf('%s range overlap', $dim);
            }
        }

        $sortNote = $a->getSortOrder() === $b->getSortOrder()
            ? ' (SAME sort_order — winner is non-deterministic, set different sort_order to pick one)'
            : '';

        $detail = empty($shared) ? 'all conditions are wildcards' : implode(', ', $shared);

        return "Overlaps with rate_id={$b->getRateId()}: {$detail}{$sortNote}";
    }
}
