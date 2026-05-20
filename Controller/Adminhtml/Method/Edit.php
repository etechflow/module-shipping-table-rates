<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use ETechFlow\ShippingTableRates\Model\MethodFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * GET /admin/etechflow_str/method/edit/method_id/X — method form.
 */
class Edit extends AbstractMethod
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly PageFactory $resultPageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly MethodFactory $methodFactory
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('method_id');
        $method = $this->methodFactory->create();
        if ($id > 0) {
            $method->load($id);
            if (!$method->getMethodId()) {
                $this->messageManager->addErrorMessage(__('This shipping method no longer exists.'));
                return $this->redirectFactory->create()->setPath('*/*/');
            }
        }

        $this->coreRegistry->register('etechflow_str_method', $method);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('ETechFlow_ShippingTableRates::manage');
        $resultPage->getConfig()->getTitle()->prepend(
            $id > 0
                ? __('Edit Method "%1"', $method->getName())
                : __('New Shipping Method')
        );
        return $resultPage;
    }
}
