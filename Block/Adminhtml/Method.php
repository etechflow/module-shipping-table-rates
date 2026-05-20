<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml;

use Magento\Backend\Block\Widget\Grid\Container;

/**
 * Container for the methods listing — provides the page header + "Add New"
 * button. The grid itself is rendered by Method\Grid.
 */
class Method extends Container
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_blockGroup = 'ETechFlow_ShippingTableRates';
        $this->_controller = 'adminhtml_method';
        $this->_headerText = __('Shipping Methods');
        $this->_addButtonLabel = __('Add New Method');

        parent::_construct();
    }
}
