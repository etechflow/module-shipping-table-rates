<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method\Edit;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;

/**
 * Renders the admin live-cart simulator widget under the method edit form.
 *
 * Merchants input cart parameters (country, weight, qty, subtotal, shipping
 * types) and click "Simulate". The widget AJAXes to Simulate controller
 * and renders the result inline — which methods matched, total cost, which
 * rate rows contributed, the formula breakdown.
 *
 * The whole point: stop merchants having to drive a real browser checkout
 * to debug "why isn't my rate applying?". One-click answer.
 */
class Simulator extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly Country $countrySource,
        private readonly GroupManagementInterface $customerGroupManagement,
        private readonly FormKey $formKeyService,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return int|null
     */
    public function getMethodId(): ?int
    {
        $method = $this->coreRegistry->registry('etechflow_str_method');
        return $method ? $method->getMethodId() : null;
    }

    /**
     * @return string
     */
    public function getSimulateUrl(): string
    {
        return $this->getUrl('etechflow_str/method/simulate');
    }

    /**
     * @return string  CSRF form key the AJAX call submits
     */
    public function getFormKey(): string
    {
        return $this->formKeyService->getFormKey();
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    public function getCountries(): array
    {
        $options = [['value' => '', 'label' => __('-- Any country --')]];
        foreach ($this->countrySource->toOptionArray(false) as $row) {
            if (is_array($row) && isset($row['value'], $row['label']) && !is_array($row['value'])) {
                $options[] = ['value' => (string) $row['value'], 'label' => (string) $row['label']];
            }
        }
        return $options;
    }

    /**
     * @return array<int, array{value:int, label:string}>
     */
    public function getCustomerGroups(): array
    {
        $options = [];
        try {
            foreach ($this->customerGroupManagement->getLoggedInGroups() as $group) {
                $options[] = ['value' => (int) $group->getId(), 'label' => (string) $group->getCode()];
            }
        } catch (\Throwable $e) {
            // best-effort — fall back to no options if group management
            // unavailable in this admin context
        }
        array_unshift($options, ['value' => 0, 'label' => __('Guest (Not logged in)')->render()]);
        return $options;
    }
}
