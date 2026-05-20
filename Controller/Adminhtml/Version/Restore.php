<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Version;

use ETechFlow\ShippingTableRates\Controller\Adminhtml\Method\AbstractMethod;
use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;

/**
 * POST /admin/etechflow_str/version/restore/version_id/X
 *
 * Rolls a method + its rates back to a previously-captured snapshot.
 * VersionRepository::restore() snapshots the CURRENT state first so even
 * the rollback is undoable — merchants who restore the wrong version can
 * undo by restoring the auto-snapshot from one step ago.
 */
class Restore extends AbstractMethod
{
    public const ADMIN_RESOURCE = 'ETechFlow_ShippingTableRates::manage';

    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly RedirectFactory $redirectFactory,
        private readonly VersionRepository $versionRepository,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $versionId = (int) $this->getRequest()->getParam('version_id');

        if ($versionId <= 0) {
            $this->messageManager->addErrorMessage(__('Version id is required.'));
            return $redirect->setPath('etechflow_str/method/index');
        }

        // Look up the method_id so we can redirect back to its edit page
        $connection = $this->resource->getConnection();
        $methodId = (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('etechflow_str_version'), 'method_id')
                ->where('version_id = ?', $versionId)
        );

        try {
            $this->versionRepository->restore($versionId);
            $this->messageManager->addSuccessMessage(
                __('Restored to version %1. The previous state was automatically snapshotted, so this rollback is itself reversible.', $versionId)
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Could not restore the version.'));
        }

        if ($methodId > 0) {
            return $redirect->setPath('etechflow_str/method/edit', ['method_id' => $methodId]);
        }
        return $redirect->setPath('etechflow_str/method/index');
    }
}
