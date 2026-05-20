<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Console\Command;

use ETechFlow\ShippingTableRates\Model\CartContext;
use ETechFlow\ShippingTableRates\Model\Conflict\ConflictDetector;
use ETechFlow\ShippingTableRates\Model\Csv\CsvImporter;
use ETechFlow\ShippingTableRates\Model\Csv\CsvSchema;
use ETechFlow\ShippingTableRates\Model\MatchResult;
use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\Rate;
use ETechFlow\ShippingTableRates\Model\RateFactory;
use ETechFlow\ShippingTableRates\Model\RateMatcher;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method\CollectionFactory as MethodCollectionFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate as RateResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;
use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Headless end-to-end verification of the v1.1.0 admin + engine pipeline.
 *
 * Closes the gap between "unit tests pass" and "I clicked through the UI
 * and it works": no browser, no merchant account, just CLI proof that the
 * full save → version → match → conflict-scan → restore lifecycle works,
 * plus a parity check for every v1.1 Amasty-parity feature.
 *
 * Exercised in sequence:
 *
 *   v1.0 lifecycle (steps 1-9):
 *     1. Cleanup any previous test artefacts (idempotent — re-runnable)
 *     2. Create a test Method  → exercises MethodResource::save
 *     3. Add a test Rate       → exercises RateResource::save
 *     4. Match the rate        → exercises RateMatcher pipeline end-to-end
 *     5. Snapshot the state    → exercises VersionRepository::snapshot
 *     6. Run conflict scan     → exercises ConflictDetector with real data
 *     7. Delete the rate       → exercises RateResource::delete
 *     8. Restore the snapshot  → exercises VersionRepository::restore
 *     9. Verify rate is back   → confirms restore actually reconstructed state
 *
 *   v1.1 Amasty parity features (steps 10-16):
 *    10. F1 — Weight Unit Conversion Rate (per-rate lb↔kg conversion)
 *    11. F2 — {day} / {name} method-name template variables
 *    12. F3 — Use price after discount (per-method subtotal mode)
 *    13. F4 — Ship These Shipping Types for Free (per-method override)
 *    14. F5 — CSV delete_row directive against the live etechflow_str_rate table
 *    15. F6 — Method-level store-view scoping (out-of-scope methods skipped)
 *    16. F7 — Volumetric / dimensional weight (chargeable weight)
 *
 * Each v1.1 step wipes the test method's rates before running, sets up
 * its own scenario, asserts the expected match cost or import outcome,
 * then resets the method state for the next step.
 *
 * Exit code: 0 on full pass, 1 on any failure. Always cleans up even on
 * failure via finally — never leaves orphan test data in the merchant's DB.
 *
 *   bin/magento etechflow:str:verify
 *
 * Same pattern as bin/magento etechflow:nde:verify built for the
 * NextDayEligibility module.
 */
class VerifyCommand extends Command
{
    private const TEST_METHOD_CODE = 'etechflow_str_verify_test';

    public function __construct(
        private readonly AppState $appState,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly MethodCollectionFactory $methodCollectionFactory,
        private readonly RateFactory $rateFactory,
        private readonly RateResource $rateResource,
        private readonly RateCollectionFactory $rateCollectionFactory,
        private readonly VersionRepository $versionRepository,
        private readonly ConflictDetector $conflictDetector,
        private readonly RateMatcher $matcher,
        private readonly ResourceConnection $resource,
        private readonly CsvImporter $csvImporter
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('etechflow:str:verify')
            ->setDescription('Run an end-to-end programmatic check of the Shipping Table Rates v1.1.0 admin + engine pipeline (v1.0 lifecycle + 7 Amasty-parity features).');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $output->writeln('<info>=== STR end-to-end verification ===</info>');
        $output->writeln('');

        $allPassed = true;
        $methodId = null;

        try {
            // 1. Cleanup any previous run
            $this->step($output, '1. Cleanup any prior test artefacts');
            $this->cleanupExisting();
            $this->pass($output);

            // 2. Create a test method
            $this->step($output, '2. Create a test method');
            $method = $this->methodFactory->create();
            $method->setData([
                'code'                     => self::TEST_METHOD_CODE,
                'name'                     => 'STR Verify Test Method',
                'is_active'                => 1,
                'sort_order'               => 99999,
                'multi_type_mode'          => 'sum',
                'free_shipping_compatible' => 1,
            ]);
            $this->methodResource->save($method);
            $methodId = (int) $method->getMethodId();
            if ($methodId <= 0) {
                throw new \RuntimeException('Method save returned no method_id');
            }
            $this->pass($output, "method_id={$methodId}");

            // 3. Add a test rate
            $this->step($output, '3. Add a rate rule to the method');
            $rate = $this->rateFactory->create();
            $rate->setData([
                'method_id'        => $methodId,
                'country_code'     => 'GB',
                'weight_from'      => 0,
                'weight_to'        => 5,
                'rate_base'        => 9.99,
                'rate_per_kg'      => 0,
                'rate_per_product' => 0,
                'rate_percent'     => 0,
                'sort_order'       => 0,
                'is_active'        => 1,
            ]);
            $this->rateResource->save($rate);
            $rateId = (int) $rate->getRateId();
            if ($rateId <= 0) {
                throw new \RuntimeException('Rate save returned no rate_id');
            }
            $this->pass($output, "rate_id={$rateId}");

            // 4. Match: should find the rate for a GB cart at 2kg
            $this->step($output, '4. RateMatcher finds the rate for a GB / 2kg cart');
            $context = new CartContext(
                countryCode:     'GB',
                regionCode:      '',
                city:            '',
                postcode:        '',
                weight:          2.0,
                qty:             1,
                subtotal:        25.0,
                customerGroupId: 0,
                shippingTypes:   []
            );
            $match = $this->matcher->match($method, $context);
            if ($match === null) {
                throw new \RuntimeException('Matcher returned null — expected a match');
            }
            if (abs($match->totalCost - 9.99) > 0.001) {
                throw new \RuntimeException(sprintf(
                    'Matcher returned cost %.4f, expected 9.99',
                    $match->totalCost
                ));
            }
            $this->pass($output, sprintf('total_cost=%.2f', $match->totalCost));

            // 5. Snapshot the state
            $this->step($output, '5. VersionRepository snapshots the method state');
            $versionId = $this->versionRepository->snapshot($method, 'STR verify pre-delete snapshot');
            if ($versionId === null) {
                throw new \RuntimeException('snapshot() returned null — expected a version_id');
            }
            $this->pass($output, "version_id={$versionId}");

            // 6. Conflict scan — should be clean (only one rate exists)
            $this->step($output, '6. ConflictDetector reports no conflicts for a single-rate method');
            $rate->load($rateId);  // refresh
            $conflicts = $this->conflictDetector->detect($rate);
            if (!empty($conflicts)) {
                throw new \RuntimeException(sprintf(
                    'Expected 0 conflicts on a single-rate method, got %d: %s',
                    count($conflicts),
                    json_encode($conflicts)
                ));
            }
            $this->pass($output, '0 conflicts');

            // 7. Delete the rate
            $this->step($output, '7. Delete the rate');
            $this->rateResource->delete($rate);
            $stillThere = $this->rateCollectionFactory->create()
                ->addFieldToFilter('rate_id', $rateId)
                ->getSize();
            if ($stillThere > 0) {
                throw new \RuntimeException('Rate still in DB after delete');
            }
            $this->pass($output);

            // 8. Restore the snapshot
            $this->step($output, '8. VersionRepository restores the snapshot');
            $this->versionRepository->restore($versionId);
            $this->pass($output);

            // 9. Verify the rate came back (matcher should find a rate again)
            $this->step($output, '9. The deleted rate is reconstructed by restore');
            $method->load($methodId);  // re-load the restored method
            $match2 = $this->matcher->match($method, $context);
            if ($match2 === null) {
                throw new \RuntimeException(
                    'After restore, matcher returned null — restored rate is not matching'
                );
            }
            if (abs($match2->totalCost - 9.99) > 0.001) {
                throw new \RuntimeException(sprintf(
                    'After restore, matcher returned cost %.4f, expected 9.99',
                    $match2->totalCost
                ));
            }
            $this->pass($output, sprintf('total_cost=%.2f (same as before delete)', $match2->totalCost));

            // ----- v1.1.0 Amasty parity feature checks -------------------
            // Each step below sets up its own rate(s) and tears them down
            // before the next step, so the verify stays independent and
            // re-runnable. The original test method (`$method`) is reused.

            // 10. Feature 1: weight_unit_conversion_rate (lbs → kg)
            $this->step($output, '10. F1 — Weight Unit Conversion Rate divides billing weight');
            $this->wipeRates($methodId);
            $convRate = $this->rateFactory->create();
            $convRate->setData([
                'method_id'                   => $methodId,
                'country_code'                => 'GB',
                'rate_base'                   => 0,
                'rate_per_kg'                 => 2.0,
                'weight_unit_conversion_rate' => 2.2046,  // 10 lb cart ÷ 2.2046 = 4.5359 kg billed
                'is_active'                   => 1,
            ]);
            $this->rateResource->save($convRate);
            $convCtx = $this->buildContext(['weight' => 10.0]);
            $match = $this->matcher->match($method, $convCtx);
            if ($match === null) {
                throw new \RuntimeException('F1: matcher returned null');
            }
            // Expected: 10 / 2.2046 × £2 = £9.0719…
            if (abs($match->totalCost - 9.0719) > 0.01) {
                throw new \RuntimeException(sprintf(
                    'F1: expected cost ≈ £9.07 (10 lb / 2.2046 × £2/kg), got £%.4f',
                    $match->totalCost
                ));
            }
            $this->pass($output, sprintf('cost £%.2f for 10 lb cart × £2/kg', $match->totalCost));

            // 11. Feature 2: {day} and {name} method-name template variables
            $this->step($output, '11. F2 — {day} / {name} method-name interpolation');
            $this->wipeRates($methodId);
            $tplRate = $this->rateFactory->create();
            $tplRate->setData([
                'method_id'      => $methodId,
                'country_code'   => 'GB',
                'rate_base'      => 4.99,
                'delivery_label' => '2',
                'name_delivery'  => 'Tracked',
                'is_active'      => 1,
            ]);
            $this->rateResource->save($tplRate);
            $tplMatch = $this->matcher->match($method, $this->buildContext());
            if ($tplMatch === null) {
                throw new \RuntimeException('F2: matcher returned null');
            }
            $title = $tplMatch->interpolateMethodName('Royal Mail {name} ({day} days)');
            if ($title !== 'Royal Mail Tracked (2 days)') {
                throw new \RuntimeException(sprintf(
                    'F2: expected "Royal Mail Tracked (2 days)", got "%s"',
                    $title
                ));
            }
            $this->pass($output, "title=\"{$title}\"");

            // 12. Feature 3: use_price_after_discount + use_price_including_tax
            $this->step($output, '12. F3 — Use price after discount steers subtotal-percent term');
            $this->wipeRates($methodId);
            $method->setData('use_price_after_discount', 1);
            $method->setData('use_price_including_tax', 0);
            $this->methodResource->save($method);
            $pctRate = $this->rateFactory->create();
            $pctRate->setData([
                'method_id'    => $methodId,
                'country_code' => 'GB',
                'rate_base'    => 0,
                'rate_percent' => 10.0,
                'is_active'    => 1,
            ]);
            $this->rateResource->save($pctRate);
            $f3Ctx = new CartContext(
                countryCode:           'GB',
                regionCode:            '',
                city:                  '',
                postcode:              '',
                weight:                0.0,
                qty:                   1,
                subtotal:              100.0,
                customerGroupId:       0,
                shippingTypes:         [],
                subtotalAfterDiscount: 80.0,   // Discount applied
            );
            $match = $this->matcher->match($method, $f3Ctx);
            if ($match === null) {
                throw new \RuntimeException('F3: matcher returned null');
            }
            // 10% × 80 (post-discount) = £8, not £10 (pre-discount)
            if (abs($match->totalCost - 8.0) > 0.01) {
                throw new \RuntimeException(sprintf(
                    'F3: expected £8 (10%% × post-discount £80), got £%.4f',
                    $match->totalCost
                ));
            }
            $this->pass($output, sprintf('cost £%.2f (10%% × £80 post-discount, not £100 pre)', $match->totalCost));
            // Reset for next step
            $method->setData('use_price_after_discount', 0);
            $this->methodResource->save($method);

            // 13. Feature 4: ship_for_free_types
            $this->step($output, '13. F4 — Ship-for-free types zero out per-type cost');
            $this->wipeRates($methodId);
            $method->setData('ship_for_free_types', 'fragile');
            $this->methodResource->save($method);
            $freeRate = $this->rateFactory->create();
            $freeRate->setData([
                'method_id'     => $methodId,
                'country_code'  => 'GB',
                'shipping_type' => 'fragile',
                'rate_base'     => 12.50,  // Would charge £12.50 without the override
                'is_active'     => 1,
            ]);
            $this->rateResource->save($freeRate);
            $freeCtx = $this->buildContext(['shippingTypes' => ['fragile']]);
            $match = $this->matcher->match($method, $freeCtx);
            if ($match === null) {
                throw new \RuntimeException('F4: matcher returned null');
            }
            if (abs($match->totalCost - 0.0) > 0.01) {
                throw new \RuntimeException(sprintf(
                    'F4: expected £0 (fragile is ship-for-free), got £%.4f',
                    $match->totalCost
                ));
            }
            $this->pass($output, sprintf('cost £%.2f for fragile cart (rate base was £12.50)', $match->totalCost));
            $method->setData('ship_for_free_types', null);
            $this->methodResource->save($method);

            // 14. Feature 5: CSV delete_row directive hits the real DB
            $this->step($output, '14. F5 — CSV delete_row=1 removes a real rate from the DB');
            $this->wipeRates($methodId);
            // Seed a rate the CSV will target for deletion
            $seedRate = $this->rateFactory->create();
            $seedRate->setData([
                'method_id'    => $methodId,
                'country_code' => 'DE',
                'weight_from'  => 0,
                'weight_to'    => 5,
                'rate_base'    => 7.50,
                'is_active'    => 1,
            ]);
            $this->rateResource->save($seedRate);
            $seedId = (int) $seedRate->getRateId();
            // Build an in-memory CSV with one delete_row row matching the seed
            $csvHandle = fopen('php://memory', 'r+');
            if ($csvHandle === false) {
                throw new \RuntimeException('F5: failed to open in-memory CSV');
            }
            fputcsv($csvHandle, CsvSchema::getColumnKeys(), ',', '"', '\\');
            $deleteRow = array_fill(0, count(CsvSchema::getColumnKeys()), '');
            $deleteRow[array_search('country_code', CsvSchema::getColumnKeys(), true)] = 'DE';
            $deleteRow[array_search('weight_from',  CsvSchema::getColumnKeys(), true)] = '0';
            $deleteRow[array_search('weight_to',    CsvSchema::getColumnKeys(), true)] = '5';
            $deleteRow[array_search('delete_row',   CsvSchema::getColumnKeys(), true)] = '1';
            fputcsv($csvHandle, $deleteRow, ',', '"', '\\');
            $importResult = $this->csvImporter->import($method, $csvHandle, CsvImporter::MODE_APPEND);
            fclose($csvHandle);
            if (!$importResult->success || $importResult->rowsDeleted !== 1) {
                throw new \RuntimeException(sprintf(
                    'F5: expected success + 1 deletion, got success=%s rowsDeleted=%d',
                    $importResult->success ? 'true' : 'false',
                    $importResult->rowsDeleted
                ));
            }
            // Confirm the rate is gone from the DB
            $stillThere = $this->rateCollectionFactory->create()
                ->addFieldToFilter('rate_id', $seedId)
                ->getSize();
            if ($stillThere > 0) {
                throw new \RuntimeException('F5: rate still exists after CSV delete_row');
            }
            $this->pass($output, 'delete_row=1 removed rate from etechflow_str_rate');

            // 15. Feature 6: store-view + customer-group scoping skips out-of-scope methods
            $this->step($output, '15. F6 — Method scope filter skips out-of-scope carts');
            $this->wipeRates($methodId);
            $scopeRate = $this->rateFactory->create();
            $scopeRate->setData([
                'method_id'    => $methodId,
                'country_code' => 'GB',
                'rate_base'    => 5.99,
                'is_active'    => 1,
            ]);
            $this->rateResource->save($scopeRate);
            // Scope to store 99 — a store id the test context (0) doesn't have
            $method->setData('store_view_ids', '99');
            $this->methodResource->save($method);
            $scopeNullMatch = $this->matcher->match($method, $this->buildContext(['storeId' => 0]));
            if ($scopeNullMatch !== null) {
                throw new \RuntimeException('F6: expected null match for out-of-scope store, got a match');
            }
            // Now flip the context to be in scope; matcher should match
            $scopeOkMatch = $this->matcher->match($method, $this->buildContext(['storeId' => 99]));
            if ($scopeOkMatch === null) {
                throw new \RuntimeException('F6: expected match for in-scope store=99, got null');
            }
            $this->pass($output, 'out-of-scope skipped; in-scope matched');
            $method->setData('store_view_ids', null);
            $this->methodResource->save($method);

            // 16. Feature 7: volumetric / dimensional weight (chargeable weight)
            $this->step($output, '16. F7 — Volumetric weight overrides actual when larger');
            $this->wipeRates($methodId);
            $method->setData('use_volumetric_weight', 1);
            $method->setData('volumetric_divisor', 5000.0);
            $this->methodResource->save($method);
            $volRate = $this->rateFactory->create();
            $volRate->setData([
                'method_id'    => $methodId,
                'country_code' => 'GB',
                'weight_from'  => 5.0,   // Range 5-10kg — actual 2kg wouldn't match here
                'weight_to'    => 10.0,
                'rate_base'    => 0,
                'rate_per_kg'  => 2.0,
                'is_active'    => 1,
            ]);
            $this->rateResource->save($volRate);
            // Cart: 2kg actual, 30×30×30=27000 cm³ ÷ 5000 = 5.4kg volumetric → chargeable 5.4
            $volCtx = $this->buildContext(['weight' => 2.0, 'volumetricCm3' => 27000.0]);
            $match = $this->matcher->match($method, $volCtx);
            if ($match === null) {
                throw new \RuntimeException('F7: expected match (chargeable 5.4 fits 5-10 range), got null');
            }
            // 5.4 × £2/kg = £10.80
            if (abs($match->totalCost - 10.80) > 0.01) {
                throw new \RuntimeException(sprintf(
                    'F7: expected £10.80 (5.4 kg chargeable × £2/kg), got £%.4f',
                    $match->totalCost
                ));
            }
            $this->pass($output, sprintf('chargeable 5.4 kg from 27000 cm³ / 5000, cost £%.2f', $match->totalCost));
            $method->setData('use_volumetric_weight', 0);
            $method->setData('volumetric_divisor', null);
            $this->methodResource->save($method);

            $output->writeln('');
            $output->writeln('<info>✅ ALL CHECKS PASSED. v1.1.0 admin + engine pipeline (with 7 Amasty parity features) verified end-to-end.</info>');
        } catch (\Throwable $e) {
            $allPassed = false;
            $output->writeln('');
            $output->writeln('<error>❌ FAIL: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>at ' . $e->getFile() . ':' . $e->getLine() . '</error>');
        } finally {
            // Always cleanup — even on failure — so the next run starts clean
            try {
                $this->cleanupExisting();
            } catch (\Throwable $cleanupErr) {
                $output->writeln('<error>Cleanup also failed: ' . $cleanupErr->getMessage() . '</error>');
                $allPassed = false;
            }
        }

        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Delete the test method (and via FK cascade, its rates + versions).
     */
    private function cleanupExisting(): void
    {
        $collection = $this->methodCollectionFactory->create();
        $collection->addFieldToFilter('code', self::TEST_METHOD_CODE);

        foreach ($collection as $method) {
            $this->methodResource->delete($method);
        }

        // Belt-and-braces: clean any stray rate / version rows tied to
        // dropped methods — shouldn't be needed thanks to the FK cascade
        // but defensive against partial-cleanup states.
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('etechflow_str_rate'),
            ['method_id NOT IN (?)' => array_map('intval',
                $connection->fetchCol(
                    $connection->select()
                        ->from($this->resource->getTableName('etechflow_str_method'), 'method_id')
                )
            )]
        );
    }

    private function step(OutputInterface $output, string $label): void
    {
        $output->write('  ' . $label . ' ... ');
    }

    private function pass(OutputInterface $output, string $detail = ''): void
    {
        $output->writeln('<info>OK</info>' . ($detail !== '' ? " ({$detail})" : ''));
    }

    /**
     * Delete every rate row attached to the test method. Used between v1.1
     * feature steps so each block starts from a known-empty state.
     */
    private function wipeRates(int $methodId): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('etechflow_str_rate'),
            ['method_id = ?' => $methodId]
        );
    }

    /**
     * Build a CartContext with sensible defaults for the verify steps.
     * Override individual fields via the $overrides array — uses named args
     * matching CartContext's constructor.
     *
     * @param array<string, mixed> $overrides
     * @return CartContext
     */
    private function buildContext(array $overrides = []): CartContext
    {
        $defaults = [
            'countryCode'     => 'GB',
            'regionCode'      => '',
            'city'            => '',
            'postcode'        => '',
            'weight'          => 2.0,
            'qty'             => 1,
            'subtotal'        => 25.0,
            'customerGroupId' => 0,
            'shippingTypes'   => [],
        ];
        return new CartContext(...array_merge($defaults, $overrides));
    }
}
