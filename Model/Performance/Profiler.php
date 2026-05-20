<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Performance;

/**
 * Thin profiler-tagging helper for the STR module's hot paths.
 *
 * Wraps Tideways span calls so traces captured in production are filterable
 * by `ETechFlow_STR_*` instead of relying on Magento's class-name auto-trace.
 * No-op when Tideways isn't installed — Blackfire / New Relic auto-instrument
 * via class+method names and don't need explicit spans.
 *
 * Self-contained (no dependency on a shared ETechFlow module) so DI works the
 * same on any merchant's install regardless of which other ETechFlow modules
 * they have enabled.
 *
 * Designed never to break checkout: every Tideways call is wrapped in
 * try/catch so a probe-version skew between server PHP extension and library
 * cannot bubble up into a customer-facing error.
 *
 * Usage:
 *
 *   $span = Profiler::start('ETechFlow_STR_collectRates');
 *   try {
 *       // ... hot path body
 *   } finally {
 *       Profiler::stop($span);
 *   }
 */
final class Profiler
{
    private static ?bool $tidewaysAvailable = null;

    /**
     * Open a span. Returns the Tideways Span object or null when not installed.
     *
     * @param string $name Span label. Convention: ETechFlow_<MODULE>_<EntryPoint>.
     * @return object|null
     */
    public static function start(string $name): ?object
    {
        if (self::$tidewaysAvailable === null) {
            self::$tidewaysAvailable = class_exists('\\Tideways\\Profiler', false);
        }
        if (!self::$tidewaysAvailable) {
            return null;
        }
        try {
            return \Tideways\Profiler::createSpan($name);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Close a span opened by start(). Safe to call with null.
     *
     * @param object|null $span
     */
    public static function stop(?object $span): void
    {
        if ($span === null) {
            return;
        }
        try {
            if (method_exists($span, 'stopTimer')) {
                $span->stopTimer();
            }
        } catch (\Throwable $e) {
            // Never let instrumentation surface to the customer.
        }
    }
}
