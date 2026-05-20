<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\Method\Edit;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;

/**
 * Renders the versioning panel under the method edit form.
 *
 * Lists every snapshot for the current method (newest first) with the
 * merchant-supplied or auto-generated label, the admin user who created
 * it, the timestamp, and a one-click Restore action.
 *
 * The Restore submit goes through VersionRepository::restore() which
 * snapshots the CURRENT state first so the rollback is itself reversible.
 */
class Versions extends Template
{
    /**
     * Limit on rows shown — keeps the edit page snappy for methods with
     * hundreds of versions. v1.x can add pagination if anyone asks.
     */
    private const MAX_VISIBLE_VERSIONS = 25;

    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly ResourceConnection $resource,
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
     * Load recent versions for the current method, newest first.
     *
     * @return array<int, array{
     *   version_id:int,
     *   label:string,
     *   created_by:string,
     *   created_at:string,
     *   restore_url:string
     * }>
     */
    public function getVersions(): array
    {
        $methodId = $this->getMethodId();
        if (!$methodId) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('etechflow_str_version'),
                    ['version_id', 'label', 'created_by', 'created_at']
                )
                ->where('method_id = ?', $methodId)
                ->order('created_at DESC')
                ->limit(self::MAX_VISIBLE_VERSIONS)
        );

        $versions = [];
        foreach ($rows as $row) {
            $versions[] = [
                'version_id' => (int) $row['version_id'],
                'label'      => (string) $row['label'],
                'created_by' => (string) ($row['created_by'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'restore_url' => $this->getUrl(
                    'etechflow_str/version/restore',
                    ['version_id' => (int) $row['version_id']]
                ),
            ];
        }
        return $versions;
    }
}
