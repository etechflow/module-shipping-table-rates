<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Console\Command;

use ETechFlow\ShippingTableRates\Model\CartContext;
use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\RateCalculator;
use ETechFlow\ShippingTableRates\Model\RateFactory;
use ETechFlow\ShippingTableRates\Model\RateMatcher;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method\CollectionFactory as MethodCollectionFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate as RateResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:str:perf [--iterations=N] [--json[=path]]`
 *
 * Micro-benchmark the hottest STR code paths against the live install + DB.
 * Mirrors the DD perf CLI so merchants get the same before/after diff
 * workflow across modules:
 *
 *   git checkout main && bin/magento etechflow:str:perf | tee /tmp/before.txt
 *   git checkout feature/x && bin/magento etechflow:str:perf | tee /tmp/after.txt
 *   diff /tmp/before.txt /tmp/after.txt
 *
 * Each path is run N times (default 100). Reports min/median/p95/max in
 * milliseconds. The first run is discarded — it includes one-time class
 * autoloading + JIT warm-up that doesn't represent steady-state cost.
 *
 * Idempotent. Seeds a test method + 20 rate rows in the etechflow_str_method
 * / etechflow_str_rate tables, runs the benchmarks, then cleans them up — no
 * DB writes survive the command.
 *
 * Cost basis: the rate matcher is the inner-loop hot path called once per
 * active method per checkout shipping-rate request. A merchant with 5 methods
 * × 20 rates per method pays roughly 5 × per-match cost per quote request.
 */
class PerfCommand extends Command
{
    private const OPT_ITERATIONS = 'iterations';
    private const OPT_JSON       = 'json';
    private const TEST_METHOD_CODE = 'etechflow_str_perf_test';

    public function __construct(
        private readonly AppState $appState,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly MethodCollectionFactory $methodCollectionFactory,
        private readonly RateFactory $rateFactory,
        private readonly RateResource $rateResource,
        private readonly RateMatcher $matcher,
        private readonly RateCalculator $calculator,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:str:perf')
            ->setDescription('Micro-benchmark the hottest Shipping Table Rates code paths. Run before + after deploys to spot regressions.')
            ->addOption(
                self::OPT_ITERATIONS,
                'i',
                InputOption::VALUE_REQUIRED,
                'Iterations per benchmark (first call is discarded for warm-up).',
                '100'
            )
            ->addOption(
                self::OPT_JSON,
                null,
                InputOption::VALUE_OPTIONAL,
                'Emit machine-readable JSON. Pass a path to write to file; pass with no value to write to stdout (and suppress the human-readable output).',
                false
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $iterations = max(2, (int) $input->getOption(self::OPT_ITERATIONS));
        $jsonOpt    = $input->getOption(self::OPT_JSON);
        $jsonMode   = $jsonOpt !== false;
        $jsonPath   = is_string($jsonOpt) && $jsonOpt !== '' ? $jsonOpt : null;

        // When JSON is requested without a path we send JSON to stdout and
        // silence the human-readable output so the caller can pipe directly.
        $textOutput = ($jsonMode && $jsonPath === null) ? new \Symfony\Component\Console\Output\NullOutput() : $output;

        $textOutput->writeln('<info>=== STR perf micro-benchmark ===</info>');
        $textOutput->writeln(sprintf('Iterations per path: %d (first discarded as warm-up)', $iterations));
        $textOutput->writeln('');

        $methodId = null;
        $results  = [];

        try {
            // ----- cleanup any leftover rows from a previous failed run -----
            $this->cleanupExisting();

            // ----- seed -----
            $method = $this->methodFactory->create();
            $method->setData([
                'code'                     => self::TEST_METHOD_CODE,
                'name'                     => 'STR Perf Test Method',
                'is_active'                => 1,
                'sort_order'               => 99998,
                'multi_type_mode'          => 'sum',
                'free_shipping_compatible' => 1,
            ]);
            $this->methodResource->save($method);
            $methodId = (int) $method->getMethodId();
            if ($methodId <= 0) {
                throw new \RuntimeException('Seed failed: method save returned no method_id');
            }

            // 20 rate rows with varied conditions — exercises the per-row
            // condition matching loop without being so large that the
            // benchmark hides the median in tail latency.
            for ($i = 0; $i < 20; $i++) {
                $rate = $this->rateFactory->create();
                $rate->setData([
                    'method_id'        => $methodId,
                    'country_code'     => 'GB',
                    'weight_from'      => (float) $i,
                    'weight_to'        => (float) ($i + 5),
                    'qty_from'         => 1,
                    'qty_to'           => 100,
                    'subtotal_from'    => 0.0,
                    'subtotal_to'      => 100000.0,
                    'rate_base'        => 5.00 + ($i * 0.10),
                    'rate_per_kg'      => 1.00,
                    'rate_per_product' => 0.50,
                    'rate_percent'     => 0,
                    'sort_order'       => $i,
                    'is_active'        => 1,
                ]);
                $this->rateResource->save($rate);
            }

            // Refresh from DB so the in-memory object matches what the matcher
            // will load (admin save → cache → match is the steady-state path).
            $method->load($methodId);

            // Reusable cart contexts
            $context = new CartContext(
                countryCode:     'GB',
                regionCode:      '',
                city:            '',
                postcode:        '',
                weight:          3.5,
                qty:             2,
                subtotal:        49.99,
                customerGroupId: 0,
                shippingTypes:   []
            );

            // ----- benchmarks -----

            $results[] = $this->bench(
                $textOutput,
                'RateCalculator::calculate (pure formula)',
                $iterations,
                fn() => $this->calculator->calculate($context, 5.0, 0.5, 1.0, 0.0)
            );

            $results[] = $this->bench(
                $textOutput,
                'RateCalculator::aggregate (3-cost sum)',
                $iterations,
                fn() => $this->calculator->aggregate([5.0, 7.5, 9.99], 'sum')
            );

            $results[] = $this->bench(
                $textOutput,
                'RateMatcher::match (20 rates, GB / 3.5kg)',
                $iterations,
                fn() => $this->matcher->match($method, $context)
            );

            // Context that misses all rates so we measure the "early reject"
            // path — every checkout for a country we don't ship to lands here.
            $missContext = new CartContext(
                countryCode:     'JP',
                regionCode:      '',
                city:            '',
                postcode:        '',
                weight:          3.5,
                qty:             2,
                subtotal:        49.99,
                customerGroupId: 0,
                shippingTypes:   []
            );
            $results[] = $this->bench(
                $textOutput,
                'RateMatcher::match (no rates match, JP cart)',
                $iterations,
                fn() => $this->matcher->match($method, $missContext)
            );

            $textOutput->writeln('');
            $textOutput->writeln('<info>=== guidance ===</info>');
            $textOutput->writeln('Every match() call above runs once per active method on every');
            $textOutput->writeln('checkout shipping-rate request. A merchant with 5 methods pays');
            $textOutput->writeln('5× per-match cost per quote.');
            $textOutput->writeln('');
            $textOutput->writeln('Healthy ranges on a warm cache:');
            $textOutput->writeln('  * RateCalculator::calculate: < 0.05ms p95');
            $textOutput->writeln('  * RateMatcher::match:        < 5ms p95 (per method)');
            $textOutput->writeln('');
            $textOutput->writeln('If RateMatcher::match exceeds 10ms p95 with < 50 rates per method,');
            $textOutput->writeln('open a profiler trace on the checkout page — see');
            $textOutput->writeln('docs/etechflow-profiler-setup.md for the workflow.');

            if ($jsonMode) {
                $this->emitJson($results, $iterations, $jsonPath, $output);
            }
        } finally {
            try {
                $this->cleanupExisting();
            } catch (\Throwable $cleanupErr) {
                $textOutput->writeln('<error>Cleanup failed: ' . $cleanupErr->getMessage() . '</error>');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Run $fn $iterations times. Discard the first run (warm-up). Report
     * min / median / p95 / max in milliseconds.
     *
     * @return array{label: string, iterations: int, min: float, median: float, p95: float, max: float}
     */
    private function bench(OutputInterface $output, string $label, int $iterations, callable $fn): array
    {
        // Warm-up
        $fn();

        $times = [];
        for ($i = 1; $i < $iterations; $i++) {
            $t0 = hrtime(true);
            $fn();
            $times[] = (hrtime(true) - $t0) / 1_000_000;  // ns → ms
        }

        sort($times);
        $count = count($times);
        $min    = $times[0];
        $median = $times[(int) ($count / 2)];
        $p95    = $times[(int) ($count * 0.95)];
        $max    = $times[$count - 1];

        $output->writeln(sprintf(
            "  %s\n      min %6.3fms  median %6.3fms  p95 %6.3fms  max %6.3fms",
            $label,
            $min,
            $median,
            $p95,
            $max
        ));

        return [
            'label'      => $label,
            'iterations' => $count,
            'min'        => $min,
            'median'     => $median,
            'p95'        => $p95,
            'max'        => $max,
        ];
    }

    /**
     * Emit the benchmark results as a JSON document. Goes to stdout when no
     * path is given (so callers can pipe), to the file otherwise.
     *
     * @param array<int, array{label: string, iterations: int, min: float, median: float, p95: float, max: float}> $results
     */
    private function emitJson(array $results, int $iterations, ?string $path, OutputInterface $stdout): void
    {
        $doc = [
            'module'           => 'etechflow_shippingtablerates',
            'command'          => 'etechflow:str:perf',
            'generated_at_iso' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'iterations'       => $iterations,
            'php_version'      => PHP_VERSION,
            'results'          => $results,
        ];
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($path === null) {
            $stdout->writeln($json);
            return;
        }

        $written = @file_put_contents($path, $json . "\n");
        if ($written === false) {
            $stdout->writeln('<error>Failed to write JSON to ' . $path . '</error>');
            return;
        }
        $stdout->writeln('<info>JSON written to ' . $path . '</info>');
    }

    /**
     * Delete the test method (and via FK cascade, its rates).
     */
    private function cleanupExisting(): void
    {
        $collection = $this->methodCollectionFactory->create();
        $collection->addFieldToFilter('code', self::TEST_METHOD_CODE);

        foreach ($collection as $method) {
            $this->methodResource->delete($method);
        }

        // Belt-and-braces in case the FK didn't cascade (defensive).
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
}
