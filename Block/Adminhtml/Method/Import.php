<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * Renders the CSV import + export panel under the method form. Two simple
 * controls: file upload + replace/append toggle for import; one-click
 * export link.
 */
class Import extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return int|null
     */
    public function getMethodId(): ?int
    {
        $method = $this->coreRegistry->registry('etechflow_str_method');
        return $method ? $method->getMethodId() : null;
    }

    /**
     * @return string
     */
    public function getImportFormUrl(): string
    {
        return $this->getUrl('*/*/import', ['method_id' => $this->getMethodId()]);
    }

    /**
     * @return string
     */
    public function getExportUrl(): string
    {
        return $this->getUrl('*/*/export', ['method_id' => $this->getMethodId()]);
    }
}
