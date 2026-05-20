<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\ForwardFactory;

/**
 * GET /admin/etechflow_str/method/new — forwards to the edit action
 * with no id so the form renders blank.
 */
class NewAction extends AbstractMethod
{
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Registry $coreRegistry,
        private readonly ForwardFactory $resultForwardFactory
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        return $this->resultForwardFactory->create()->forward('edit');
    }
}
