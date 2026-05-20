<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Rate;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Registry;

/**
 * Shared ACL + DI setup for the Rate admin controllers.
 */
abstract class AbstractRate extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_ShippingTableRates::manage';

    public function __construct(
        Context $context,
        protected readonly Registry $coreRegistry
    ) {
        parent::__construct($context);
    }
}
