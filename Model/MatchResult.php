<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

/**
 * Result of a successful rate match — what to charge + which rate(s)
 * contributed. The list of winning rates is preserved so the admin
 * live-cart simulator can render the explanation step-by-step:
 *
 *   "Method 'UK Express' charged £12.50 because:
 *     - Rate #42 (NULL shipping type) → £8.50
 *     - Rate #57 (fragile)            → £4.00
 *    Aggregated via 'sum' mode = £12.50"
 *
 * Immutable. Returned by RateMatcher::match().
 */
class MatchResult
{
    /**
     * @param Rate[] $winningRates The rules that contributed (one per shipping-type group)
     * @param float  $totalCost    Final aggregated + clamped cost
     */
    public function __construct(
        public readonly array $winningRates,
        public readonly float $totalCost
    ) {
    }

    /**
     * Convenience: return the longest delivery_days among the winning rates,
     * since at checkout we show one ETA per method and the longest is the
     * customer-honest answer for a mixed cart.
     *
     * @return int|null  null if no winning rate carries a delivery_days value
     */
    public function getLongestDeliveryDays(): ?int
    {
        $days = [];
        foreach ($this->winningRates as $rate) {
            $d = $rate->getDeliveryDays();
            if ($d !== null) {
                $days[] = $d;
            }
        }
        return empty($days) ? null : max($days);
    }

    /**
     * Concatenate the per-rate comments — used for the checkout "method
     * comment" UI. NULL/empty comments are skipped.
     *
     * @return string
     */
    public function getCombinedComment(): string
    {
        $parts = [];
        foreach ($this->winningRates as $rate) {
            $c = trim($rate->getComment());
            if ($c !== '') {
                $parts[] = $c;
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Substitute `{day}` and `{name}` placeholders in the method-name
     * template using the winning rates' delivery_label / name_delivery
     * values. Matches Amasty's template-variable feature.
     *
     * Substitution source for `{day}`:
     *  1. The winning rate with the LONGEST delivery_days — most customer-
     *     honest for mixed-type carts (slowest leg sets the expectation).
     *  2. That rate's delivery_label if set; otherwise the integer
     *     delivery_days cast to string; otherwise empty.
     *
     * Substitution source for `{name}`:
     *  - The same winning rate's name_delivery, or empty if NULL.
     *
     * Templates with NO placeholders are returned unchanged — the caller
     * can detect this (template === result) and fall back to the legacy
     * "(X days)" suffix behaviour.
     *
     * Trailing/double whitespace introduced by empty substitutions is
     * collapsed so "Express {day} days" becomes "Express days" not
     * "Express  days" when {day} is empty.
     *
     * @param string $template Method name with optional placeholders
     * @return string
     */
    public function interpolateMethodName(string $template): string
    {
        if (!str_contains($template, '{day}') && !str_contains($template, '{name}')) {
            return $template;
        }

        $source = $this->pickInterpolationSource();
        if ($source === null) {
            // No winning rates — replace placeholders with empty and tidy
            return $this->tidyWhitespace(str_replace(['{day}', '{name}'], ['', ''], $template));
        }

        $dayLabel = $source->getDeliveryLabel();
        if ($dayLabel === null) {
            $days = $source->getDeliveryDays();
            $dayLabel = $days !== null ? (string) $days : '';
        }

        $nameLabel = $source->getNameDelivery() ?? '';

        return $this->tidyWhitespace(
            str_replace(['{day}', '{name}'], [$dayLabel, $nameLabel], $template)
        );
    }

    /**
     * Choose the winning rate to source template-variable values from.
     * Prefers the rate with the longest delivery_days (customer-honest);
     * falls back to the first winning rate when none have delivery_days
     * set; returns null if there are no winners at all.
     *
     * @return Rate|null
     */
    private function pickInterpolationSource(): ?Rate
    {
        $longest     = null;
        $longestDays = -1;
        foreach ($this->winningRates as $rate) {
            $d = $rate->getDeliveryDays();
            if ($d !== null && $d > $longestDays) {
                $longestDays = $d;
                $longest     = $rate;
            }
        }
        return $longest ?? ($this->winningRates[0] ?? null);
    }

    /**
     * Collapse multi-whitespace and trim — keeps method titles clean when an
     * empty substitution leaves a gap in the template.
     *
     * @param string $value
     * @return string
     */
    private function tidyWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
