<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Tools;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * One-click "Disable Flat Rate" target for the banner above the Methods grid.
 *
 * Writes carriers/flatrate/active = 0 to core_config_data, cleans the
 * config cache so the change takes effect immediately, then redirects
 * back to the Methods grid with a success message. The merchant can
 * always re-enable Flat Rate under Stores -> Config -> Sales ->
 * Shipping Methods -> Flat Rate.
 */
class DisableFlatrate extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_ShippingTableRates::config';

    public function __construct(
        Context $context,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        try {
            $this->configWriter->save('carriers/flatrate/active', '0');
            $this->cacheTypeList->cleanType('config');
            $this->messageManager->addSuccessMessage(
                __('Magento\'s built-in Flat Rate carrier has been disabled. STR is now the only shipping carrier active.')
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not disable Flat Rate: %1', $e->getMessage())
            );
        }
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('etechflow_str/method/index');
    }
}
