<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method;

use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Registry;

/**
 * Container for the method edit form — provides Save / Save and Continue /
 * Delete buttons + the form block underneath.
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
        $this->_controller = 'adminhtml_method';
        $this->_objectId   = 'method_id';

        parent::_construct();

        $method = $this->_coreRegistry->registry('etechflow_str_method');
        $isNew  = !$method || !$method->getMethodId();

        $this->buttonList->update('save', 'label', __('Save Method'));
        $this->buttonList->update('save', 'class', 'save primary');
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
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getHeaderText(): \Magento\Framework\Phrase
    {
        $method = $this->_coreRegistry->registry('etechflow_str_method');
        if ($method && $method->getMethodId()) {
            return __('Edit Method "%1"', $this->escapeHtml($method->getName()));
        }
        return __('New Shipping Method');
    }
}
