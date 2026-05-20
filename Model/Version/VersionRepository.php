<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Version;

use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Snapshot + restore for the etechflow_str_version table.
 *
 * v1.0 differentiator: every method save snapshots the prior method + rate
 * state into a JSON blob so a one-click rollback works even after a bulk
 * CSV import accidentally wipes pricing. Amasty / MageWorx don't have this
 * — multiple competitor reviews mention merchants needing vendor support
 * to recover after a bad import.
 *
 * The hook that calls snapshot() is invoked by the save controller BEFORE
 * applying changes. Restore is exposed for the rollback action.
 */
class VersionRepository
{
    /**
     * Constructor.
     *
     * @param ResourceConnection    $resource
     * @param RateCollectionFactory $rateCollectionFactory
     * @param AuthSession           $authSession
     * @param DateTime              $dateTime
     * @param LoggerInterface       $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RateCollectionFactory $rateCollectionFactory,
        private readonly AuthSession $authSession,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Capture the current state of a method + all its rates as a versioned
     * snapshot row. Returns the new version_id, or null if the method has
     * never been saved before (no state to snapshot).
     *
     * @param Method      $method
     * @param string|null $label Optional merchant label; auto-generated from
     *                           the timestamp + admin username if null.
     * @return int|null
     */
    public function snapshot(Method $method, ?string $label = null): ?int
    {
        $methodId = $method->getMethodId();
        if ($methodId === null) {
            // Brand-new method — nothing to snapshot yet
            return null;
        }

        try {
            $rates = $this->loadRatesForMethod($methodId);

            $snapshot = [
                'method' => $method->getData(),  // raw column array
                'rates'  => array_map(static fn($r) => $r->getData(), $rates),
                'snapshot_at' => $this->dateTime->gmtDate(),
            ];

            $username = $this->getCurrentAdminUsername();

            $connection = $this->resource->getConnection();
            $connection->insert(
                $this->resource->getTableName('etechflow_str_version'),
                [
                    'method_id'     => $methodId,
                    'label'         => $label ?? $this->autoLabel($username),
                    'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                    'created_by'    => $username,
                ]
            );

            return (int) $connection->lastInsertId();
        } catch (\Throwable $e) {
            // Versioning is best-effort — if it fails, we log but don't
            // block the save. Merchants would rather lose a snapshot than
            // be unable to save their rates.
            $this->logger->warning(
                'ETechFlow_ShippingTableRates: VersionRepository snapshot failed.',
                ['method_id' => $methodId, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Restore a method + rates to a previously-captured snapshot state.
     * Deletes current rates, replaces them with the snapshot's rates, and
     * writes the snapshot's method columns back to the method row.
     *
     * Snapshots the CURRENT state first (under an auto-label) so even the
     * rollback itself is undoable.
     *
     * @param int $versionId
     * @throws LocalizedException If the version row is missing or malformed.
     */
    public function restore(int $versionId): void
    {
        $connection = $this->resource->getConnection();
        $versionTable = $this->resource->getTableName('etechflow_str_version');
        $methodTable  = $this->resource->getTableName('etechflow_str_method');
        $rateTable    = $this->resource->getTableName('etechflow_str_rate');

        $row = $connection->fetchRow(
            $connection->select()->from($versionTable)->where('version_id = ?', $versionId)
        );
        if (!$row) {
            throw new LocalizedException(__('Version %1 not found.', $versionId));
        }

        $snapshot = json_decode((string) $row['snapshot_json'], true);
        if (!is_array($snapshot) || !isset($snapshot['method'], $snapshot['rates'])) {
            throw new LocalizedException(__('Version %1 is malformed.', $versionId));
        }

        $methodId = (int) $row['method_id'];
        $methodData = $snapshot['method'];
        $rates = $snapshot['rates'];

        // Snapshot current state before overwriting — rollback-of-rollback safety
        $connection->insert($versionTable, [
            'method_id'     => $methodId,
            'label'         => 'Pre-rollback auto-snapshot ' . $this->dateTime->gmtDate(),
            'snapshot_json' => json_encode([
                'method' => $connection->fetchRow($connection->select()->from($methodTable)->where('method_id = ?', $methodId)) ?: [],
                'rates'  => $connection->fetchAll($connection->select()->from($rateTable)->where('method_id = ?', $methodId)),
                'snapshot_at' => $this->dateTime->gmtDate(),
            ], JSON_UNESCAPED_SLASHES),
            'created_by'    => $this->getCurrentAdminUsername(),
        ]);

        $connection->beginTransaction();
        try {
            // Restore method columns (excluding immutable PK + timestamps)
            $methodUpdate = $methodData;
            unset($methodUpdate['method_id'], $methodUpdate['created_at'], $methodUpdate['updated_at']);
            if (!empty($methodUpdate)) {
                $connection->update($methodTable, $methodUpdate, ['method_id = ?' => $methodId]);
            }

            // Replace all rates: delete + reinsert
            $connection->delete($rateTable, ['method_id = ?' => $methodId]);
            foreach ($rates as $rateData) {
                $insertable = $rateData;
                unset($insertable['rate_id'], $insertable['created_at'], $insertable['updated_at']);
                $insertable['method_id'] = $methodId;
                $connection->insert($rateTable, $insertable);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error(
                'ETechFlow_ShippingTableRates: Version restore failed; transaction rolled back.',
                ['version_id' => $versionId, 'exception' => $e->getMessage()]
            );
            throw new LocalizedException(__('Rollback failed: %1', $e->getMessage()));
        }
    }

    /**
     * Load all Rate models for a method (used by snapshot()).
     *
     * @param int $methodId
     * @return \ETechFlow\ShippingTableRates\Model\Rate[]
     */
    private function loadRatesForMethod(int $methodId): array
    {
        $collection = $this->rateCollectionFactory->create();
        $collection->addFieldToFilter('method_id', $methodId);
        /** @var \ETechFlow\ShippingTableRates\Model\Rate[] $items */
        $items = $collection->getItems();
        return $items;
    }

    /**
     * @param string|null $username
     * @return string
     */
    private function autoLabel(?string $username): string
    {
        $when = $this->dateTime->gmtDate();
        $by   = $username !== null && $username !== '' ? " by {$username}" : '';
        return "Auto snapshot {$when}{$by}";
    }

    /**
     * @return string
     */
    private function getCurrentAdminUsername(): string
    {
        try {
            $user = $this->authSession->getUser();
            return $user ? (string) $user->getUserName() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
