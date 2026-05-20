<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method\Edit;

use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Model\Config\Source\Yesno;

/**
 * Method edit form. Two visible sections: General (code, name, on/off,
 * sort_order, free-shipping compat) and Rate Limits (min/max + multi-type
 * mode). Plus a hidden CSV import/export panel that lives in the
 * accompanying phtml template.
 */
class Form extends Generic
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly Yesno $yesno,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * @return $this
     */
    protected function _prepareForm(): self
    {
        $method = $this->_coreRegistry->registry('etechflow_str_method');

        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create([
            'data' => [
                'id'      => 'edit_form',
                'action'  => $this->getUrl('*/*/save'),
                'method'  => 'post',
                'enctype' => 'multipart/form-data',
            ],
        ]);
        $form->setHtmlIdPrefix('etechflow_str_method_');

        $general = $form->addFieldset(
            'general',
            ['legend' => __('General')]
        );

        if ($method && $method->getMethodId()) {
            $general->addField('method_id', 'hidden', ['name' => 'method_id']);
        }

        $general->addField('code', 'text', [
            'name'     => 'code',
            'label'    => __('Code'),
            'title'    => __('Code'),
            'required' => true,
            'note'     => __('Stable machine code used in the carrier_method identifier at checkout. Use snake_case: e.g. <code>uk_next_day</code>.'),
        ]);

        $general->addField('name', 'text', [
            'name'     => 'name',
            'label'    => __('Method Name'),
            'title'    => __('Method Name'),
            'required' => true,
            'note'     => __('Customer-visible label at checkout. Per-store-view overrides supported via a Phase 3b feature; for now this is the default label.'),
        ]);

        $general->addField('is_active', 'select', [
            'name'   => 'is_active',
            'label'  => __('Active'),
            'title'  => __('Active'),
            'values' => $this->yesno->toOptionArray(),
            'note'   => __('Off = method is hidden at checkout but rates are preserved.'),
        ]);

        $general->addField('sort_order', 'text', [
            'name'    => 'sort_order',
            'label'   => __('Sort Order'),
            'title'   => __('Sort Order'),
            'class'   => 'validate-not-negative-number',
            'note'    => __('Lower numbers appear first in the methods list at checkout.'),
            'value'   => 0,
        ]);

        $general->addField('free_shipping_compatible', 'select', [
            'name'   => 'free_shipping_compatible',
            'label'  => __('Free Shipping Compatible'),
            'title'  => __('Free Shipping Compatible'),
            'values' => $this->yesno->toOptionArray(),
            'note'   => __('When Yes, Magento\'s native free-shipping promotion (coupons, cart price rules) will override our rate when triggered. When No, this method always charges the calculated rate regardless of promo state.'),
        ]);

        $limits = $form->addFieldset(
            'limits',
            ['legend' => __('Rate Limits & Multi-Type Handling')]
        );

        $limits->addField('min_rate', 'text', [
            'name'  => 'min_rate',
            'label' => __('Minimum Rate'),
            'title' => __('Minimum Rate'),
            'note'  => __('Method-wide floor — calculated rate will be raised to at least this. Leave blank for no minimum.'),
        ]);

        $limits->addField('max_rate', 'text', [
            'name'  => 'max_rate',
            'label' => __('Maximum Rate'),
            'title' => __('Maximum Rate'),
            'note'  => __('Method-wide cap — calculated rate will be reduced to at most this. Leave blank for no maximum.'),
        ]);

        $limits->addField('multi_type_mode', 'select', [
            'name'   => 'multi_type_mode',
            'label'  => __('Multi-Shipping-Type Handling'),
            'title'  => __('Multi-Shipping-Type Handling'),
            'values' => [
                ['value' => 'sum', 'label' => __('Sum — add all matching rates')],
                ['value' => 'min', 'label' => __('Min — pick the cheapest matching rate')],
                ['value' => 'max', 'label' => __('Max — pick the most expensive matching rate')],
            ],
            'note'   => __('When a cart contains items of multiple shipping types (e.g. fragile + oversized), this controls how their per-type rates aggregate into the final method cost.'),
        ]);

        $pricing = $form->addFieldset(
            'subtotal_basis',
            ['legend' => __('Subtotal Basis')]
        );

        $pricing->addField('use_price_after_discount', 'select', [
            'name'   => 'use_price_after_discount',
            'label'  => __('Use Price After Discount'),
            'title'  => __('Use Price After Discount'),
            'values' => $this->yesno->toOptionArray(),
            'note'   => __('When Yes, the cart subtotal used by this method (for the Subtotal range filter AND the Percent of Subtotal formula term) is the POST-discount subtotal. When No (default), uses the pre-discount subtotal.'),
        ]);

        $pricing->addField('use_price_including_tax', 'select', [
            'name'   => 'use_price_including_tax',
            'label'  => __('Use Price Including Tax'),
            'title'  => __('Use Price Including Tax'),
            'values' => $this->yesno->toOptionArray(),
            'note'   => __('When Yes, the cart subtotal used by this method includes tax. When No (default), uses the pre-tax subtotal. Combines with the Use Price After Discount flag — the four combinations correspond to the four ways Magento exposes subtotals.'),
        ]);

        $freebie = $form->addFieldset(
            'free_shipping_types',
            ['legend' => __('Ship-for-Free Overrides')]
        );

        $freebie->addField('ship_for_free_types', 'text', [
            'name'  => 'ship_for_free_types',
            'label' => __('Ship These Shipping Types for Free'),
            'title' => __('Ship These Shipping Types for Free'),
            'note'  => __('Comma-separated list of <code>shipping_type</code> values that should ship at zero cost on this method (e.g. <code>fragile, oversized</code>). Rates targeting those types still match, but their cost contribution is forced to 0 before the multi-type aggregation. Wildcard rates (no shipping_type) are NOT zeroed by this list. Leave blank for no overrides.'),
        ]);

        $scope = $form->addFieldset(
            'scope',
            ['legend' => __('Method Scope')]
        );

        $scope->addField('store_view_ids', 'text', [
            'name'  => 'store_view_ids',
            'label' => __('Visible in Store Views'),
            'title' => __('Visible in Store Views'),
            'note'  => __('Comma-separated list of Magento store IDs (find them under Stores → All Stores) this method should appear in. Leave blank to apply to ALL store views. Example: <code>1,2</code> restricts the method to stores 1 and 2 only.'),
        ]);

        $scope->addField('customer_group_ids', 'text', [
            'name'  => 'customer_group_ids',
            'label' => __('Available to Customer Groups'),
            'title' => __('Available to Customer Groups'),
            'note'  => __('Comma-separated list of customer-group IDs (Customers → Customer Groups). Leave blank to apply to ALL groups. Example: <code>0,1</code> = NOT LOGGED IN + General. This is the method-level scope; per-rate customer-group filters still apply to individual rate rules within this method.'),
        ]);

        $volumetric = $form->addFieldset(
            'volumetric',
            ['legend' => __('Volumetric / Dimensional Weight')]
        );

        $volumetric->addField('use_volumetric_weight', 'select', [
            'name'   => 'use_volumetric_weight',
            'label'  => __('Use Volumetric Weight'),
            'title'  => __('Use Volumetric Weight'),
            'values' => $this->yesno->toOptionArray(),
            'note'   => __('When Yes, the cart weight used by this method is <code>max(actual_weight, length × width × height ÷ divisor)</code> across all cart items — couriers bill on whichever is greater. Requires the product dimension attributes (Length / Width / Height in cm) on the products. Carts without dimension data fall back to actual weight, so it\'s safe to turn this on before every product has dimensions filled in.'),
        ]);

        $volumetric->addField('volumetric_divisor', 'text', [
            'name'  => 'volumetric_divisor',
            'label' => __('Volumetric Divisor (cm³ per kg)'),
            'title' => __('Volumetric Divisor (cm³ per kg)'),
            'note'  => __('Common courier values: <code>5000</code> (DHL / FedEx Air / Royal Mail Tracked), <code>6000</code> (FedEx Ground), <code>4000</code> (UPS small parcel premium). Leave blank for the carrier-default <strong>5000</strong>. Only consulted when Use Volumetric Weight is Yes.'),
        ]);

        if ($method && $method->getMethodId()) {
            $form->setValues($method->getData());
        }

        $form->setUseContainer(true);
        $this->setForm($form);

        return parent::_prepareForm();
    }
}
