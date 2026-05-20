<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use ETechFlow\ShippingTableRates\Model\Csv\CsvExporter;
use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Registry;

/**
 * GET /admin/etechflow_str/method/export/method_id/X — download a CSV of
 * the method's rates. Round-trips cleanly through Import.
 */
class Export extends AbstractMethod
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly RedirectFactory $redirectFactory,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly CsvExporter $exporter,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $methodId = (int) $this->getRequest()->getParam('method_id');
        if ($methodId <= 0) {
            $this->messageManager->addErrorMessage(__('Method id is required.'));
            return $this->redirectFactory->create()->setPath('*/*/');
        }

        $method = $this->methodFactory->create();
        $this->methodResource->load($method, $methodId);
        if (!$method->getMethodId()) {
            $this->messageManager->addErrorMessage(__('This shipping method no longer exists.'));
            return $this->redirectFactory->create()->setPath('*/*/');
        }

        // Build the CSV in a memory buffer so we never touch disk for typical sizes
        $buffer = fopen('php://temp', 'r+');
        $this->exporter->export($method, $buffer);
        rewind($buffer);
        $contents = stream_get_contents($buffer);
        fclose($buffer);

        $filename = sprintf(
            'shipping-rates-%s-%s.csv',
            preg_replace('/[^a-z0-9_-]/i', '-', $method->getCode() ?: 'method'),
            date('Ymd-His')
        );

        return $this->fileFactory->create(
            $filename,
            $contents,
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }
}
