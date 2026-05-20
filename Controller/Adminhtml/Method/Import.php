<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use ETechFlow\ShippingTableRates\Model\Csv\CsvImporter;
use ETechFlow\ShippingTableRates\Model\Csv\ImportResult;
use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;

/**
 * POST /admin/etechflow_str/method/import/method_id/X — upload + parse a CSV
 * of rate rules into the given method.
 *
 * Renders per-row validation errors as flash messages so the merchant can
 * fix and re-upload without bouncing between admin + editor.
 */
class Import extends AbstractMethod
{
    /**
     * 5 MB cap is generous — Amasty's perf claim is 23K rates × 1.7M postcodes,
     * which fits comfortably under 5 MB even at one rate per line.
     */
    private const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly RedirectFactory $redirectFactory,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly CsvImporter $importer
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $methodId = (int) $this->getRequest()->getParam('method_id');

        if ($methodId <= 0) {
            $this->messageManager->addErrorMessage(__('Method id is required.'));
            return $redirect->setPath('*/*/');
        }

        $method = $this->methodFactory->create();
        $this->methodResource->load($method, $methodId);
        if (!$method->getMethodId()) {
            $this->messageManager->addErrorMessage(__('This shipping method no longer exists.'));
            return $redirect->setPath('*/*/');
        }

        $file = $this->getRequest()->getFiles('csv_file');
        if (empty($file) || empty($file['tmp_name'])) {
            $this->messageManager->addErrorMessage(__('No CSV file was uploaded.'));
            return $redirect->setPath('*/*/edit', ['method_id' => $methodId]);
        }

        if (!empty($file['error'])) {
            $this->messageManager->addErrorMessage(__('Upload failed (PHP error code %1).', (int) $file['error']));
            return $redirect->setPath('*/*/edit', ['method_id' => $methodId]);
        }

        if (!empty($file['size']) && $file['size'] > self::MAX_FILE_SIZE_BYTES) {
            $this->messageManager->addErrorMessage(__(
                'CSV file too large: %1 bytes (max %2). Split into smaller files or contact support to raise the limit.',
                (int) $file['size'],
                self::MAX_FILE_SIZE_BYTES
            ));
            return $redirect->setPath('*/*/edit', ['method_id' => $methodId]);
        }

        $mode = $this->getRequest()->getParam('import_mode') === CsvImporter::MODE_APPEND
            ? CsvImporter::MODE_APPEND
            : CsvImporter::MODE_REPLACE;

        try {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                throw new LocalizedException(__('Could not open the uploaded CSV file.'));
            }

            try {
                $result = $this->importer->import($method, $handle, $mode);
            } finally {
                fclose($handle);
            }

            $this->surfaceResult($result);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addExceptionMessage($e, __('CSV import failed.'));
        }

        return $redirect->setPath('*/*/edit', ['method_id' => $methodId]);
    }

    /**
     * Convert an ImportResult into flash messages — success → green;
     * each error row → its own red error message so the admin can fix
     * them one at a time.
     *
     * @param ImportResult $result
     */
    private function surfaceResult(ImportResult $result): void
    {
        if ($result->success) {
            $this->messageManager->addSuccessMessage($result->getSummary());
            return;
        }

        $this->messageManager->addErrorMessage($result->getSummary());

        // Cap to a reasonable number to avoid drowning the merchant in
        // 500 error messages on a 500-row bad file.
        $shown = 0;
        foreach ($result->errorsByRow as $rowNum => $errors) {
            foreach ($errors as $err) {
                if ($shown++ >= 25) {
                    $this->messageManager->addErrorMessage(__(
                        '... (%1 more errors hidden — fix the visible ones first and re-upload)',
                        array_sum(array_map('count', $result->errorsByRow)) - 25
                    ));
                    return;
                }
                $this->messageManager->addErrorMessage(__('Row %1: %2', $rowNum, $err));
            }
        }
    }
}
