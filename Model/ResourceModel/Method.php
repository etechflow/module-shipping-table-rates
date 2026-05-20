<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for Method — maps the AbstractModel to the
 * etechflow_str_method table.
 */
class Method extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('etechflow_str_method', 'method_id');
    }
}
