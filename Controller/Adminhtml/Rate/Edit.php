<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Rate;

use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\RateFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate as RateResource;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * GET /admin/etechflow_str/rate/edit?rate_id=X    — edit existing rate
 * GET /admin/etechflow_str/rate/edit?method_id=X  — new rate for method X
 */
class Edit extends AbstractRate
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly PageFactory $resultPageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly RateFactory $rateFactory,
        private readonly RateResource $rateResource,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $rateId   = (int) $this->getRequest()->getParam('rate_id');
        $methodId = (int) $this->getRequest()->getParam('method_id');

        $rate = $this->rateFactory->create();
        if ($rateId > 0) {
            $this->rateResource->load($rate, $rateId);
            if (!$rate->getRateId()) {
                $this->messageManager->addErrorMessage(__('This rate rule no longer exists.'));
                return $this->redirectFactory->create()->setPath('etechflow_str/method/index');
            }
            $methodId = $rate->getMethodId();
        } elseif ($methodId > 0) {
            $rate->setData('method_id', $methodId);
            $rate->setData('is_active', 1);
            $rate->setData('sort_order', 0);
        } else {
            $this->messageManager->addErrorMessage(__('A method_id or rate_id is required.'));
            return $this->redirectFactory->create()->setPath('etechflow_str/method/index');
        }

        // Load the parent method so the form can reference it (breadcrumbs +
        // post-save redirect target)
        $method = $this->methodFactory->create();
        $this->methodResource->load($method, $methodId);
        if (!$method->getMethodId()) {
            $this->messageManager->addErrorMessage(__('The parent method does not exist.'));
            return $this->redirectFactory->create()->setPath('etechflow_str/method/index');
        }

        $this->coreRegistry->register('etechflow_str_rate', $rate);
        $this->coreRegistry->register('etechflow_str_method', $method);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('ETechFlow_ShippingTableRates::manage');
        $resultPage->getConfig()->getTitle()->prepend(
            $rateId > 0
                ? __('Edit Rate Rule #%1', $rateId)
                : __('New Rate Rule for "%1"', $method->getName())
        );
        return $resultPage;
    }
}
