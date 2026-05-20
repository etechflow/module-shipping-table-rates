<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Registry;

/**
 * GET /admin/etechflow_str/method/index — methods listing.
 */
class Index extends AbstractMethod
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('ETechFlow_ShippingTableRates::manage');
        $resultPage->getConfig()->getTitle()->prepend(__('Shipping Table Rates'));
        $resultPage->addBreadcrumb(__('eTechFlow'), __('eTechFlow'));
        $resultPage->addBreadcrumb(__('Shipping Table Rates'), __('Shipping Table Rates'));
        return $resultPage;
    }
}
