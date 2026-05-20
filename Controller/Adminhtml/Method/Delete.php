<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

/**
 * POST /admin/etechflow_str/method/delete/method_id/X — delete a method.
 * Rates cascade via the FK declared in db_schema.xml.
 *
 * Pre-delete snapshot lets the merchant restore the method via the version
 * grid even after deletion — turns "I deleted the wrong row" into a
 * one-click recovery.
 */
class Delete extends AbstractMethod
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly RedirectFactory $redirectFactory,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly VersionRepository $versionRepository
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $id = (int) $this->getRequest()->getParam('method_id');
        if ($id <= 0) {
            $this->messageManager->addErrorMessage(__('Method id is required.'));
            return $redirect->setPath('*/*/');
        }

        $method = $this->methodFactory->create();
        $this->methodResource->load($method, $id);
        if (!$method->getMethodId()) {
            $this->messageManager->addErrorMessage(__('This shipping method no longer exists.'));
            return $redirect->setPath('*/*/');
        }

        try {
            // Snapshot before delete so a wrong delete is undoable
            $this->versionRepository->snapshot($method, 'Pre-delete snapshot');

            $this->methodResource->delete($method);
            $this->messageManager->addSuccessMessage(__('The shipping method "%1" has been deleted.', $method->getName()));
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Could not delete the method.'));
        }

        return $redirect->setPath('*/*/');
    }
}
