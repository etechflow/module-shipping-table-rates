<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use ETechFlow\ShippingTableRates\Model\CartContext;
use ETechFlow\ShippingTableRates\Model\Method as MethodModel;
use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\RateMatcher;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method\CollectionFactory as MethodCollectionFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;

/**
 * POST /admin/etechflow_str/method/simulate
 *
 * AJAX endpoint backing the admin live-cart simulator widget. Same logic
 * as `bin/magento etechflow:str:simulate` — accepts cart parameters,
 * runs RateMatcher against every active method (or just the requested
 * one), returns structured JSON for client-side rendering.
 *
 * Returned shape:
 *   {
 *     "success": true,
 *     "context": { ... echo of inputs ... },
 *     "results": [
 *       {
 *         "method_id":     42,
 *         "method_code":   "uk_standard",
 *         "method_name":   "UK Standard Delivery",
 *         "matched":       true,
 *         "total_cost":    11.99,
 *         "delivery_days": null,
 *         "comment":       "",
 *         "winners": [
 *           { "rate_id": 3, "shipping_type": null, "formula": "base=7.99 + per_kg=0.50" }
 *         ]
 *       },
 *       ...
 *     ]
 *   }
 *
 * v1.0 differentiator — Amasty / MageWorx have no equivalent admin tool.
 * Merchants currently debug rate misses by adding to a real cart and
 * eyeballing checkout; this surfaces the answer in one click.
 */
class Simulate extends AbstractMethod
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly JsonFactory $jsonFactory,
        private readonly MethodCollectionFactory $methodCollectionFactory,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly RateMatcher $matcher,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        /** @var HttpRequest $request */
        $request = $this->getRequest();
        $params  = $request->getPostValue() ?: [];

        try {
            $shippingTypes = array_filter(
                array_map('trim', explode(',', (string) ($params['shipping_types'] ?? '')))
            );

            $context = new CartContext(
                countryCode:     strtoupper(trim((string) ($params['country']     ?? 'GB'))),
                regionCode:      (string) ($params['region']    ?? ''),
                city:            (string) ($params['city']      ?? ''),
                postcode:        (string) ($params['postcode']  ?? ''),
                weight:          (float)  ($params['weight']    ?? 0.0),
                qty:             (int)    ($params['qty']       ?? 0),
                subtotal:        (float)  ($params['subtotal']  ?? 0.0),
                customerGroupId: (int)    ($params['customer_group'] ?? 0),
                shippingTypes:   array_values($shippingTypes)
            );

            $methods = $this->resolveMethods((int) ($params['method_id'] ?? 0));

            $results = [];
            foreach ($methods as $method) {
                $match = $this->matcher->match($method, $context);

                if ($match === null) {
                    $results[] = [
                        'method_id'     => $method->getMethodId(),
                        'method_code'   => $method->getCode(),
                        'method_name'   => $method->getName(),
                        'matched'       => false,
                        'total_cost'    => null,
                        'delivery_days' => null,
                        'comment'       => '',
                        'winners'       => [],
                    ];
                    continue;
                }

                $winners = [];
                foreach ($match->winningRates as $rate) {
                    $winners[] = [
                        'rate_id'       => $rate->getRateId(),
                        'shipping_type' => $rate->getShippingType(),
                        'formula'       => $this->describeFormula($rate),
                    ];
                }

                $results[] = [
                    'method_id'     => $method->getMethodId(),
                    'method_code'   => $method->getCode(),
                    'method_name'   => $method->getName(),
                    'matched'       => true,
                    'total_cost'    => $match->totalCost,
                    'delivery_days' => $match->getLongestDeliveryDays(),
                    'comment'       => $match->getCombinedComment(),
                    'winners'       => $winners,
                ];
            }

            return $result->setData([
                'success' => true,
                'context' => [
                    'country'         => $context->countryCode,
                    'region'          => $context->regionCode,
                    'city'            => $context->city,
                    'postcode'        => $context->postcode,
                    'weight'          => $context->weight,
                    'qty'             => $context->qty,
                    'subtotal'        => $context->subtotal,
                    'customer_group'  => $context->customerGroupId,
                    'shipping_types'  => $context->shippingTypes,
                ],
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_ShippingTableRates: Simulate AJAX endpoint failed.',
                ['exception' => $e->getMessage()]
            );
            return $result->setData([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load the methods to simulate against. If a specific method_id was
     * requested, just that one; otherwise all active methods.
     *
     * @param int $methodId  0 = all active methods
     * @return MethodModel[]
     */
    private function resolveMethods(int $methodId): array
    {
        if ($methodId > 0) {
            $method = $this->methodFactory->create();
            $this->methodResource->load($method, $methodId);
            return $method->getMethodId() !== null ? [$method] : [];
        }

        $collection = $this->methodCollectionFactory->create();
        $collection->addActiveFilter();
        $collection->addOrder('sort_order', 'ASC');
        /** @var MethodModel[] $items */
        $items = $collection->getItems();
        return $items;
    }

    /**
     * Format the rate formula for human reading.
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
