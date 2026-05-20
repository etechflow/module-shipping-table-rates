<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Carrier;

use ETechFlow\ShippingTableRates\Model\CartContextBuilder;
use ETechFlow\ShippingTableRates\Model\Config;
use ETechFlow\ShippingTableRates\Model\MatchResult;
use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\Performance\Profiler;
use ETechFlow\ShippingTableRates\Model\RateMatcher;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method\CollectionFactory as MethodCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method as RateMethod;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory as RateMethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * The Magento Shipping carrier for ETechFlow_ShippingTableRates.
 *
 * Registered under carriers/etechflow_str/. Implements the standard
 * AbstractCarrier + CarrierInterface contract, so Magento's MSI works
 * transparently — for multi-source carts, Magento calls collectRates()
 * per source-allocation with the appropriate items in the RateRequest.
 *
 * Algorithm:
 *  1. Bail (return false) if module is disabled — gives a clean "no rates"
 *     outcome instead of an error.
 *  2. Build a CartContext from the RateRequest.
 *  3. Load every active method, iterate; call RateMatcher per method.
 *  4. For each method that matches, append a Magento Rate\Method to the result.
 *  5. Return the populated Result, or false if NO methods matched (Magento
 *     interprets false as "this carrier has no rates for this address").
 *
 * Every exception path is logged + degraded to "no rates" so a bad rate row
 * or a missing column never crashes checkout.
 */
class TableRates extends AbstractCarrier implements CarrierInterface
{
    /** @var string */
    protected $_code = 'etechflow_str';

    /** Single-method carrier? No — we offer multiple methods per request. */
    protected $_isFixed = false;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface     $scopeConfig
     * @param ErrorFactory             $rateErrorFactory
     * @param LoggerInterface          $logger
     * @param ResultFactory            $rateResultFactory
     * @param RateMethodFactory        $rateMethodFactory
     * @param MethodCollectionFactory  $methodCollectionFactory
     * @param CartContextBuilder       $contextBuilder
     * @param RateMatcher              $matcher
     * @param Config                   $config
     * @param array                    $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly RateMethodFactory $rateMethodFactory,
        private readonly MethodCollectionFactory $methodCollectionFactory,
        private readonly CartContextBuilder $contextBuilder,
        private readonly RateMatcher $matcher,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Return all method codes this carrier could offer. Used by Magento for
     * admin shipping-method dropdowns + Cart Price Rule conditions.
     *
     * Loaded dynamically from the active methods table so merchants see
     * their actual configured methods, not a fixed list.
     *
     * @return array<string, string>  code => name
     */
    public function getAllowedMethods(): array
    {
        $methods = [];
        try {
            $collection = $this->methodCollectionFactory->create();
            $collection->addActiveFilter();
            $collection->addOrder('sort_order', 'ASC');

            /** @var Method $method */
            foreach ($collection as $method) {
                $code = $method->getCode();
                if ($code !== '') {
                    $methods[$code] = $method->getName() ?: $code;
                }
            }
        } catch (\Throwable $e) {
            $this->_logger->error(
                'ETechFlow_ShippingTableRates: getAllowedMethods failed.',
                ['exception' => $e->getMessage()]
            );
        }
        return $methods;
    }

    /**
     * Collect shipping rates for the given request. Called by Magento's
     * shipping subsystem during checkout (and admin order creation).
     *
     * @param RateRequest $request
     * @return Result|false
     */
    public function collectRates(RateRequest $request)
    {
        // Module-level kill switch + licence check (delegates to Config)
        if (!$this->config->isEnabled()) {
            return false;
        }

        // Carrier-level enable flag — wired to active in config.xml below.
        // Lets Magento's standard "disable carrier" admin UI work too.
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $span = Profiler::start('ETechFlow_STR_collectRates');
        try {
            $context = $this->contextBuilder->buildFromRateRequest($request);

            $result = $this->rateResultFactory->create();
            $appended = 0;

            $methodCollection = $this->methodCollectionFactory->create();
            $methodCollection->addActiveFilter();
            $methodCollection->addOrder('sort_order', 'ASC');

            foreach ($methodCollection as $method) {
                /** @var Method $method */
                $matchResult = $this->matcher->match($method, $context);
                if ($matchResult === null) {
                    continue;
                }

                $result->append($this->buildRateMethod($method, $matchResult));
                $appended++;
            }

            if ($appended === 0) {
                return false;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->_logger->error(
                'ETechFlow_ShippingTableRates: collectRates failed; returning no rates.',
                ['exception' => $e->getMessage()]
            );
            return false;
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * Translate a successful MatchResult into a Magento Rate\Method row.
     *
     * @param Method      $method
     * @param MatchResult $match
     * @return RateMethod
     */
    private function buildRateMethod(Method $method, MatchResult $match): RateMethod
    {
        /** @var RateMethod $rate */
        $rate = $this->rateMethodFactory->create();
        $rate->setCarrier($this->_code);
        $rate->setCarrierTitle($this->getConfigData('title') ?: __('Shipping'));
        $rate->setMethod($method->getCode());

        // {day} and {name} template variables (Amasty parity). MatchResult
        // returns the template unchanged when no placeholders are present,
        // letting us preserve the legacy "(X days)" suffix for merchants who
        // never opt into the template syntax.
        $rawName = $method->getName();
        $title   = $match->interpolateMethodName($rawName);
        if ($title === $rawName) {
            $deliveryDays = $match->getLongestDeliveryDays();
            if ($deliveryDays !== null) {
                $title .= sprintf(' (%d %s)', $deliveryDays, $deliveryDays === 1 ? 'day' : 'days');
            }
        }
        $rate->setMethodTitle($title);

        $cost = $match->totalCost;
        $rate->setCost($cost);
        $rate->setPrice($cost);

        $comment = $match->getCombinedComment();
        if ($comment !== '') {
            // Magento's Rate\Method accepts a `method_description` field
            // that some themes render under the method label.
            $rate->setData('method_description', $comment);
        }

        return $rate;
    }
}
