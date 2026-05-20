<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method;

use ETechFlow\ShippingTableRates\Model\ResourceModel\Method\CollectionFactory;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Grid as WidgetGrid;
use Magento\Backend\Block\Widget\Grid\Extended;
use Magento\Backend\Helper\Data as BackendHelper;

/**
 * Methods listing grid. Legacy block-based pattern — fast to ship, easy
 * to evolve to UI components in a polish pass.
 */
class Grid extends Extended
{
    public function __construct(
        Context $context,
        BackendHelper $backendHelper,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $backendHelper, $data);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('etechflow_str_method_grid');
        $this->setDefaultSort('sort_order');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(false);
    }

    /**
     * @return $this
     */
    protected function _prepareCollection(): self
    {
        $this->setCollection($this->collectionFactory->create());
        return parent::_prepareCollection();
    }

    /**
     * @return $this
     */
    protected function _prepareColumns(): self
    {
        $this->addColumn('method_id', [
            'header' => __('ID'),
            'index'  => 'method_id',
            'type'   => 'number',
            'width'  => '60px',
        ]);

        $this->addColumn('code', [
            'header' => __('Code'),
            'index'  => 'code',
        ]);

        $this->addColumn('name', [
            'header' => __('Name'),
            'index'  => 'name',
        ]);

        $this->addColumn('is_active', [
            'header'  => __('Active'),
            'index'   => 'is_active',
            'type'    => 'options',
            'options' => [1 => __('Yes'), 0 => __('No')],
            'width'   => '80px',
        ]);

        $this->addColumn('sort_order', [
            'header' => __('Sort Order'),
            'index'  => 'sort_order',
            'type'   => 'number',
            'width'  => '90px',
        ]);

        $this->addColumn('multi_type_mode', [
            'header'  => __('Multi-Type'),
            'index'   => 'multi_type_mode',
            'type'    => 'options',
            'options' => [
                'sum' => __('Sum'),
                'min' => __('Min'),
                'max' => __('Max'),
            ],
            'width'   => '100px',
        ]);

        $this->addColumn('action', [
            'header'    => __('Action'),
            'type'      => 'action',
            'getter'    => 'getMethodId',
            'actions'   => [
                ['caption' => __('Edit'),   'url' => ['base' => '*/*/edit'],   'field' => 'method_id'],
                ['caption' => __('Export'), 'url' => ['base' => '*/*/export'], 'field' => 'method_id'],
            ],
            'filter'    => false,
            'sortable'  => false,
            'is_system' => true,
            'width'     => '160px',
        ]);

        return parent::_prepareColumns();
    }

    /**
     * @param \ETechFlow\ShippingTableRates\Model\Method $row
     * @return string
     */
    public function getRowUrl($row): string
    {
        return $this->getUrl('*/*/edit', ['method_id' => $row->getMethodId()]);
    }
}
