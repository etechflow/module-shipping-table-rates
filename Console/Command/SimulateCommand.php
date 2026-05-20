<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Console\Command;

use ETechFlow\ShippingTableRates\Model\CartContext;
use ETechFlow\ShippingTableRates\Model\MatchResult;
use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\RateMatcher;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method\CollectionFactory as MethodCollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Headless rate simulator. Drop-in for the v1.0 live-cart-simulator
 * differentiator (Phase 5 will add a GUI version in admin).
 *
 *   bin/magento etechflow:str:simulate \
 *       --country=GB --region=ENG --postcode="SW1A 1AA" \
 *       --weight=5 --qty=3 --subtotal=100 \
 *       --customer-group=1 --shipping-types=fragile,standard
 *
 * Builds a CartContext from the CLI args, runs RateMatcher against every
 * active method, prints which methods matched + their cost + which rate
 * rows contributed. Useful for:
 *  - Smoke-testing a fresh install (does the carrier respond?)
 *  - Debugging "why isn't this rate applying?" without driving a browser
 *  - CI / monitoring (exit code 0 if at least one rate matched, 1 otherwise)
 */
class SimulateCommand extends Command
{
    public function __construct(
        private readonly MethodCollectionFactory $methodCollectionFactory,
        private readonly RateMatcher $matcher
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('etechflow:str:simulate')
            ->setDescription('Simulate a checkout cart and show which Shipping Table Rates methods + rates would apply.')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Country ISO alpha-2 (e.g. GB, US, DE)', 'GB')
            ->addOption('region', null, InputOption::VALUE_OPTIONAL, 'Region / state code or name', '')
            ->addOption('city', null, InputOption::VALUE_OPTIONAL, 'City name', '')
            ->addOption('postcode', null, InputOption::VALUE_OPTIONAL, 'Postcode (alphanumeric — UK/CA codes supported)', '')
            ->addOption('weight', null, InputOption::VALUE_REQUIRED, 'Total cart weight in store unit (kg or lb)', '1.0')
            ->addOption('qty', null, InputOption::VALUE_REQUIRED, 'Total item qty in cart', '1')
            ->addOption('subtotal', null, InputOption::VALUE_REQUIRED, 'Cart subtotal in store currency', '50.0')
            ->addOption('customer-group', null, InputOption::VALUE_OPTIONAL, 'Magento customer group ID (0=guest, 1=general, etc.)', '1')
            ->addOption('shipping-types', null, InputOption::VALUE_OPTIONAL, 'Comma-separated shipping_type values in cart (e.g. fragile,standard)', '');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shippingTypes = array_filter(
            array_map('trim', explode(',', (string) $input->getOption('shipping-types')))
        );

        $context = new CartContext(
            countryCode:     strtoupper(trim((string) $input->getOption('country'))),
            regionCode:      (string) $input->getOption('region'),
            city:            (string) $input->getOption('city'),
            postcode:        (string) $input->getOption('postcode'),
            weight:          (float) $input->getOption('weight'),
            qty:             (int) $input->getOption('qty'),
            subtotal:        (float) $input->getOption('subtotal'),
            customerGroupId: (int) $input->getOption('customer-group'),
            shippingTypes:   array_values($shippingTypes)
        );

        $output->writeln('<info>Simulating rates for cart:</info>');
        $output->writeln(sprintf('  Destination: %s / %s / %s / %s',
            $context->countryCode ?: '(any)',
            $context->regionCode ?: '(any)',
            $context->city ?: '(any)',
            $context->postcode ?: '(none)'
        ));
        $output->writeln(sprintf('  Cart: qty=%d, weight=%.2f, subtotal=%.2f', $context->qty, $context->weight, $context->subtotal));
        $output->writeln(sprintf('  Customer group: %d', $context->customerGroupId));
        $output->writeln(sprintf('  Shipping types: %s', empty($context->shippingTypes) ? '(none)' : implode(', ', $context->shippingTypes)));
        $output->writeln('');

        $methodCollection = $this->methodCollectionFactory->create();
        $methodCollection->addActiveFilter();
        $methodCollection->addOrder('sort_order', 'ASC');

        $matchedCount = 0;
        $totalMethods = 0;

        foreach ($methodCollection as $method) {
            /** @var Method $method */
            $totalMethods++;

            $output->writeln(sprintf(
                '<comment>Method "%s" (code: %s)</comment>',
                $method->getName(),
                $method->getCode()
            ));

            $match = $this->matcher->match($method, $context);
            if ($match === null) {
                $output->writeln('  <error>No rate matched</error>');
                $output->writeln('');
                continue;
            }

            $matchedCount++;
            $this->printMatchDetails($output, $match);
            $output->writeln('');
        }

        if ($totalMethods === 0) {
            $output->writeln('<error>No active shipping methods configured. Visit Sales → Operations → Shipping Table Rates to add one.</error>');
            return Command::FAILURE;
        }

        if ($matchedCount === 0) {
            $output->writeln(sprintf('<error>Checked %d method(s), none matched. Customer would see no rates from this carrier at checkout.</error>', $totalMethods));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>%d of %d method(s) matched this cart.</info>', $matchedCount, $totalMethods));
        return Command::SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @param MatchResult $match
     */
    private function printMatchDetails(OutputInterface $output, MatchResult $match): void
    {
        $output->writeln(sprintf('  <info>Total cost: %.2f</info>', $match->totalCost));

        $eta = $match->getLongestDeliveryDays();
        if ($eta !== null) {
            $output->writeln(sprintf('  Estimated delivery: %d day(s)', $eta));
        }

        $comment = $match->getCombinedComment();
        if ($comment !== '') {
            $output->writeln(sprintf('  Checkout comment: %s', $comment));
        }

        $output->writeln(sprintf('  Winning rate(s): %d row(s)', count($match->winningRates)));
        foreach ($match->winningRates as $rate) {
            $output->writeln(sprintf(
                '    - rate_id=%d  shipping_type=%s  formula=%s',
                $rate->getRateId(),
                $rate->getShippingType() ?? '(any)',
                $this->describeFormula($rate)
            ));
        }
    }

    /**
     * Format a rate's formula for human reading.
     */
    private function describeFormula(\ETechFlow\ShippingTableRates\Model\Rate $rate): string
    {
        $parts = [];
        if ($rate->getRateBase() > 0)       { $parts[] = sprintf('base=%.2f', $rate->getRateBase()); }
        if ($rate->getRatePerProduct() > 0) { $parts[] = sprintf('per_product=%.2f', $rate->getRatePerProduct()); }
        if ($rate->getRatePerKg() > 0)      { $parts[] = sprintf('per_kg=%.2f', $rate->getRatePerKg()); }
        if ($rate->getRatePercent() > 0)    { $parts[] = sprintf('percent=%.2f%%', $rate->getRatePercent()); }
        return $parts ? implode(' + ', $parts) : '(all components zero)';
    }
}
