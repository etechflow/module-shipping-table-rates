<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Rate;

use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Registry;

/**
 * Container for the rate-rule edit form. Provides Save / Save and
 * Continue / Delete / Back buttons.
 */
class Edit extends Container
{
    /**
     * @var Registry
     */
    protected $_coreRegistry = null;

    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->_coreRegistry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_blockGroup = 'ETechFlow_ShippingTableRates';
        $this->_controller = 'adminhtml_rate';
        $this->_objectId   = 'rate_id';

        parent::_construct();

        $rate = $this->_coreRegistry->registry('etechflow_str_rate');
        $isNew = !$rate || !$rate->getRateId();

        $this->buttonList->update('save', 'label', __('Save Rate Rule'));
        $this->buttonList->add(
            'save_and_continue',
            [
                'label'          => __('Save and Continue Edit'),
                'class'          => 'save',
                'data_attribute' => [
                    'mage-init' => ['button' => ['event' => 'saveAndContinueEdit', 'target' => '#edit_form']],
                ],
            ],
            -100
        );

        if ($isNew) {
            $this->buttonList->remove('delete');
        }

        $method = $this->_coreRegistry->registry('etechflow_str_method');
        if ($method && $method->getMethodId()) {
            $backUrl = $this->getUrl('etechflow_str/method/edit', ['method_id' => $method->getMethodId()]);
            $this->buttonList->update('back', 'onclick', "setLocation('{$backUrl}')");
        }
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getHeaderText(): \Magento\Framework\Phrase
    {
        $rate = $this->_coreRegistry->registry('etechflow_str_rate');
        if ($rate && $rate->getRateId()) {
            return __('Edit Rate Rule #%1', (int) $rate->getRateId());
        }

        $method = $this->_coreRegistry->registry('etechflow_str_method');
        $methodName = $method ? $this->escapeHtml($method->getName()) : '';
        return __('New Rate Rule for "%1"', $methodName);
    }
}
