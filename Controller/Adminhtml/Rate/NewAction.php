<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Rate;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

/**
 * GET /admin/etechflow_str/rate/new/method_id/X — forwards to edit
 * pre-populated with the parent method_id so the new rate associates
 * correctly.
 */
class NewAction extends AbstractRate
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly ForwardFactory $forwardFactory
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        return $this->forwardFactory->create()->forward('edit');
    }
}
