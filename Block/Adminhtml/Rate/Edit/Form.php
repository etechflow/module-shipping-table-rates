<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Rate\Edit;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

/**
 * The rate-rule edit form. Three fieldsets:
 *
 *   - Conditions: country / region / city / postcode-range / weight-range /
 *     qty-range / subtotal-range / customer-group / shipping-type
 *   - Rate formula: base + per-product + per-kg + percent
 *   - Metadata: delivery_days, comment, sort_order, is_active
 *
 * Empty fields become NULL in the DB (= "match any" for condition columns,
 * 0.0 for rate components) — the Save controller handles the coercion.
 */
class Form extends Generic
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly Yesno $yesno,
        private readonly Country $countrySource,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * @return $this
     */
    protected function _prepareForm(): self
    {
        $rate   = $this->_coreRegistry->registry('etechflow_str_rate');
        $method = $this->_coreRegistry->registry('etechflow_str_method');

        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create([
            'data' => [
                'id'     => 'edit_form',
                'action' => $this->getUrl('etechflow_str/rate/save'),
                'method' => 'post',
            ],
        ]);
        $form->setHtmlIdPrefix('etechflow_str_rate_');

        $form->addField('method_id', 'hidden', ['name' => 'method_id', 'value' => $method ? $method->getMethodId() : '']);
        if ($rate && $rate->getRateId()) {
            $form->addField('rate_id', 'hidden', ['name' => 'rate_id', 'value' => $rate->getRateId()]);
        }

        // -- Conditions
        $conditions = $form->addFieldset('conditions', ['legend' => __('Cart Conditions (leave blank to match any value)')]);

        $conditions->addField('country_code', 'select', [
            'name'   => 'country_code',
            'label'  => __('Country'),
            'values' => $this->buildCountryOptions(),
            'note'   => __('Leave as "(any)" to apply this rule regardless of destination country.'),
        ]);

        $conditions->addField('region_code', 'text', [
            'name'  => 'region_code',
            'label' => __('Region / State'),
            'note'  => __('State code (e.g. CA, NY) or full region name. Case-insensitive. Blank = any.'),
        ]);

        $conditions->addField('city', 'text', [
            'name'  => 'city',
            'label' => __('City'),
            'note'  => __('Exact city name; case-insensitive. Blank = any.'),
        ]);

        $conditions->addField('zip_from', 'text', [
            'name'  => 'zip_from',
            'label' => __('Postcode From'),
            'note'  => __('Alphanumeric — UK / Canada / Netherlands codes supported. Spaces ignored on compare. Blank = no lower bound.'),
        ]);
        $conditions->addField('zip_to', 'text', [
            'name'  => 'zip_to',
            'label' => __('Postcode To'),
            'note'  => __('Inclusive upper bound for the postcode range. Set To equal to From for a single postcode.'),
        ]);

        $conditions->addField('weight_from', 'text', [
            'name'  => 'weight_from',
            'label' => __('Cart Weight From'),
            'note'  => __('Inclusive minimum, in store weight unit (kg or lb).'),
        ]);
        $conditions->addField('weight_to', 'text', [
            'name'  => 'weight_to',
            'label' => __('Cart Weight To'),
            'note'  => __('Inclusive maximum.'),
        ]);

        $conditions->addField('qty_from', 'text', [
            'name'  => 'qty_from',
            'label' => __('Item Qty From'),
            'note'  => __('Total cart qty across all items.'),
        ]);
        $conditions->addField('qty_to', 'text', [
            'name'  => 'qty_to',
            'label' => __('Item Qty To'),
        ]);

        $conditions->addField('subtotal_from', 'text', [
            'name'  => 'subtotal_from',
            'label' => __('Subtotal From'),
            'note'  => __('In store currency. The "use discounted price" setting determines whether this matches pre- or post-coupon.'),
        ]);
        $conditions->addField('subtotal_to', 'text', [
            'name'  => 'subtotal_to',
            'label' => __('Subtotal To'),
        ]);

        $conditions->addField('customer_group_id', 'text', [
            'name'  => 'customer_group_id',
            'label' => __('Customer Group IDs'),
            'note'  => __('Comma-separated group IDs (e.g. <code>1,3,5</code>). Blank = any group.'),
        ]);

        $conditions->addField('shipping_type', 'text', [
            'name'  => 'shipping_type',
            'label' => __('Shipping Type'),
            'note'  => __('Value of the product shipping_type attribute (e.g. <code>fragile</code>, <code>oversized</code>). Blank = any.'),
        ]);

        // -- Rate formula
        $formula = $form->addFieldset('formula', ['legend' => __('Rate Formula (all components added; final cost clamped by method min/max)')]);

        $formula->addField('rate_base', 'text', [
            'name'  => 'rate_base',
            'label' => __('Base Rate'),
            'note'  => __('Flat charge added once per cart that matches this rule.'),
        ]);
        $formula->addField('rate_per_product', 'text', [
            'name'  => 'rate_per_product',
            'label' => __('Per-Product Rate'),
            'note'  => __('Multiplied by total cart qty.'),
        ]);
        $formula->addField('rate_per_kg', 'text', [
            'name'  => 'rate_per_kg',
            'label' => __('Per-Unit-of-Weight Rate'),
            'note'  => __('Multiplied by total cart weight (kg or store unit).'),
        ]);
        $formula->addField('rate_percent', 'text', [
            'name'  => 'rate_percent',
            'label' => __('Percent of Subtotal (%)'),
            'note'  => __('e.g. 5 means 5%. Applied to the cart subtotal.'),
        ]);

        $formula->addField('weight_unit_conversion_rate', 'text', [
            'name'  => 'weight_unit_conversion_rate',
            'label' => __('Weight Unit Conversion'),
            'value' => 1,
            'note'  => __(
                'Cart weight is divided by this value before the Per-Unit-of-Weight Rate is applied. '
                . 'Leave at <code>1</code> for no conversion. '
                . 'Enter <code>2.2046</code> to convert pounds to kilograms (10 lb cart ÷ 2.2046 = 4.54 kg billed). '
                . 'Enter <code>0.4536</code> to convert kilograms to pounds.'
            ),
        ]);

        // -- Metadata
        $meta = $form->addFieldset('meta', ['legend' => __('Display & Priority')]);

        $meta->addField('delivery_days', 'text', [
            'name'  => 'delivery_days',
            'label' => __('Estimated Delivery (days)'),
            'note'  => __('Integer days. Used for the legacy "(X days)" suffix when the method name does not include a {day} placeholder, and to pick the slowest winner for {day} substitution in mixed-type carts.'),
        ]);

        $meta->addField('delivery_label', 'text', [
            'name'  => 'delivery_label',
            'label' => __('Delivery Label ({day} value)'),
            'note'  => __('Optional free-text label that replaces the <code>{day}</code> placeholder in the method name. Example: <code>to Canada, 5 working days</code>. Overrides the integer days for display. Leave blank to use the integer above.'),
        ]);

        $meta->addField('name_delivery', 'text', [
            'name'  => 'name_delivery',
            'label' => __('Delivery Name ({name} value)'),
            'note'  => __('Optional free-text label that replaces the <code>{name}</code> placeholder in the method name. Example: a method named <code>Royal Mail {name}</code> with this set to <code>Tracked 24</code> displays at checkout as <em>Royal Mail Tracked 24</em>.'),
        ]);

        $meta->addField('comment', 'textarea', [
            'name'  => 'comment',
            'label' => __('Checkout Comment'),
            'style' => 'height:60px;',
            'note'  => __('Optional explanatory text displayed under the method at checkout. Safe HTML allowed (b, i, u).'),
        ]);

        $meta->addField('sort_order', 'text', [
            'name'  => 'sort_order',
            'label' => __('Priority'),
            'value' => 0,
            'note'  => __('Lower wins when multiple rules match the same cart. Use distinct values to avoid non-deterministic picks.'),
        ]);

        $meta->addField('is_active', 'select', [
            'name'   => 'is_active',
            'label'  => __('Active'),
            'values' => $this->yesno->toOptionArray(),
            'note'   => __('Off = this rule is ignored at checkout (kept in the table for future use).'),
        ]);

        if ($rate && $rate->getRateId()) {
            $form->setValues($rate->getData());
        }

        $form->setUseContainer(true);
        $this->setForm($form);

        return parent::_prepareForm();
    }

    /**
     * Build the country dropdown options with an "any country" first entry.
     *
     * @return array<int, array{value:string, label:string}>
     */
    private function buildCountryOptions(): array
    {
        $options = [['value' => '', 'label' => __('-- Any country --')->render()]];
        foreach ($this->countrySource->toOptionArray(false) as $row) {
            if (is_array($row) && isset($row['value'], $row['label']) && !is_array($row['value'])) {
                $options[] = ['value' => (string) $row['value'], 'label' => (string) $row['label']];
            }
        }
        return $options;
    }
}
