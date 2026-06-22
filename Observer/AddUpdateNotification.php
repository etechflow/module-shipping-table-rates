<?php
declare(strict_types=1);
namespace ETechFlow\ShippingTableRates\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;

class AddUpdateNotification implements ObserverInterface
{
    private const PACKAGE     = 'etechflow/module-shipping-table-rates';
    private const LATEST_URL  = 'https://license-service.etechflow.com/composer/latest/etechflow/module-shipping-table-rates.json';
    private const CACHE_KEY   = 'etechflow_str_update_check';
    private const CACHE_TTL   = 21600;
    private const MODULE_NAME = 'ETechFlow_ShippingTableRates';

    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly ResourceConnection $resource
    ) {}

    public function execute(Observer $observer): void
    {
        try {
            $latest = $this->fetchLatest();
            if (empty($latest['version'])) return;
            $installed = $this->installedVersion();
            if ($installed === '' || version_compare($installed, $latest['version'], '>=')) return;
            $title = (string)__('eTechFlow Shipping Table Rates %1 is available', $latest['version']);
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('adminnotification_inbox');
            if ((int)$conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE title = ? AND is_remove = 0", [$title]) > 0) return;
            $desc = !empty($latest['notes']) ? $latest['notes']
                : (string)__('Update available: %1 to %2. Run: composer update %3', $installed, $latest['version'], self::PACKAGE);
            $this->notifier->addNotice($title, $desc);
        } catch (\Throwable $e) {}
    }

    private function fetchLatest(): array
    {
        $raw = $this->cache->load(self::CACHE_KEY);
        if (!$raw) {
            $raw = '{}';
            try {
                $curl = $this->curlFactory->create(); $curl->setTimeout(5);
                $curl->get(self::LATEST_URL);
                if ((int)$curl->getStatus() === 200) $raw = (string)$curl->getBody();
            } catch (\Throwable $e) { $raw = '{}'; }
            $this->cache->save($raw, self::CACHE_KEY, [], self::CACHE_TTL);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['latest_version'])) return ['version' => '', 'notes' => ''];
        return ['version' => (string)$data['latest_version'], 'notes' => (string)($data['release_notes'] ?? '')];
    }

    private function installedVersion(): string
    {
        try {
            $v = $this->resource->getConnection()->fetchOne(
                'SELECT schema_version FROM ' . $this->resource->getTableName('setup_module') . ' WHERE module = ?',
                [self::MODULE_NAME]);
            return $v ? (string)$v : '';
        } catch (\Throwable $e) { return ''; }
    }
}
