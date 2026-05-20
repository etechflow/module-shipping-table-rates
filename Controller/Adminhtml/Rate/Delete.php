<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Rate;

use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\RateFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate as RateResource;
use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;

/**
 * POST /admin/etechflow_str/rate/delete?rate_id=X — delete a single rule.
 *
 * Snapshots the parent method (with this rate intact) before deletion so
 * the rollback path can recover. Wrong-delete is then a one-click
 * recovery via the version history panel.
 */
class Delete extends AbstractRate
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly RedirectFactory $redirectFactory,
        private readonly RateFactory $rateFactory,
        private readonly RateResource $rateResource,
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
        $rateId   = (int) $this->getRequest()->getParam('rate_id');

        if ($rateId <= 0) {
            $this->messageManager->addErrorMessage(__('Rate id is required.'));
            return $redirect->setPath('etechflow_str/method/index');
        }

        $rate = $this->rateFactory->create();
        $this->rateResource->load($rate, $rateId);
        if (!$rate->getRateId()) {
            $this->messageManager->addErrorMessage(__('This rate rule no longer exists.'));
            return $redirect->setPath('etechflow_str/method/index');
        }

        $methodId = $rate->getMethodId();

        try {
            // Snapshot the parent method first so the delete is reversible
            $method = $this->methodFactory->create();
            $this->methodResource->load($method, $methodId);
            if ($method->getMethodId()) {
                $this->versionRepository->snapshot($method, 'Pre-rate-delete snapshot');
            }

            $this->rateResource->delete($rate);
            $this->messageManager->addSuccessMessage(__('Rate rule #%1 deleted.', $rateId));
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Could not delete the rate rule.'));
        }

        return $redirect->setPath('etechflow_str/method/edit', ['method_id' => $methodId]);
    }
}
