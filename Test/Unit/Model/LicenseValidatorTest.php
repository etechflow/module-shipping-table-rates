<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model;

use ETechFlow\ShippingTableRates\Model\LicenseValidator;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors the NDE / BED license validator tests — every dev-host pattern, the
 * production-environment toggle precedence, the per-module + bundle key flows,
 * and the canonicalisation rules. The HMAC fragments differ from NDE/BED so
 * the per-module flow uses STR's own secret; the bundle flow uses the shared
 * secret all three modules carry.
 *
 * v1.2.0 ctor went 2-arg -> 5-arg (Cache + Curl + WriterInterface added for
 * the SP-XXXX portal flow). Cache mock stubs load() -> false so the portal
 * branch falls through to the HMAC path on every legacy test case.
 */
class LicenseValidatorTest extends TestCase
{
    private ScopeConfigInterface|MockObject $scopeConfig;
    private StoreManagerInterface|MockObject $storeManager;
    private CacheInterface|MockObject $cache;
    private Curl|MockObject $curl;
    private WriterInterface|MockObject $configWriter;
    private LicenseValidator $validator;

    protected function setUp(): void
    {
        $this->scopeConfig  = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->cache        = $this->createMock(CacheInterface::class);
        $this->curl         = $this->createMock(Curl::class);
        $this->configWriter = $this->createMock(WriterInterface::class);

        // Default: cache miss -> portal branch falls through; legacy HMAC cases
        // never hit the portal anyway because their configured keys don't start
        // with "SP-".
        $this->cache->method('load')->willReturn(false);

        $this->validator = new LicenseValidator(
            $this->scopeConfig,
            $this->storeManager,
            $this->cache,
            $this->curl,
            $this->configWriter
        );
    }

    /**
     * Wire the StoreManager so getCurrentHost() returns this host.
     */
    private function setHost(string $host, string $protocol = 'https'): void
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn("{$protocol}://{$host}/");
        $this->storeManager->method('getStore')->willReturn($store);
    }

    /**
     * Configure all three relevant XML paths in one place.
     */
    private function setConfig(string $licenseKey, string $bundleKey, string $productionEnv = '1'): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(static function ($path) use ($licenseKey, $bundleKey, $productionEnv) {
                return match ($path) {
                    LicenseValidator::XML_PATH_LICENSE_KEY            => $licenseKey,
                    LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY     => $bundleKey,
                    LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT => $productionEnv,
                    default                                           => '',
                };
            });
    }

    // -----------------------------------------------------------------
    // Dev-host bypass (no licence needed)
    // -----------------------------------------------------------------

    public function testDevHostBypassesLicensing(): void
    {
        $this->setHost('app.magento2.test');
        $this->setConfig('', '');
        $this->assertTrue($this->validator->isValid());
    }

    public function testLocalhostBypassesLicensing(): void
    {
        $this->setHost('localhost');
        $this->setConfig('', '');
        $this->assertTrue($this->validator->isValid());
    }

    public function testStagingPrefixBypassesLicensing(): void
    {
        $this->setHost('staging.example.com');
        $this->setConfig('', '');
        $this->assertTrue($this->validator->isValid());
    }

    public function testRfc1918PrivateIpBypassesLicensing(): void
    {
        $this->setHost('192.168.1.100');
        $this->setConfig('', '');
        $this->assertTrue($this->validator->isValid());
    }

    public function testNgrokTunnelBypassesLicensing(): void
    {
        $this->setHost('abc123.ngrok.io');
        $this->setConfig('', '');
        $this->assertTrue($this->validator->isValid());
    }

    public function testMagentoCloudBypassesLicensing(): void
    {
        $this->setHost('staging-foo.magento.cloud');
        $this->setConfig('', '');
        $this->assertTrue($this->validator->isValid());
    }

    // -----------------------------------------------------------------
    // Production Environment toggle
    // -----------------------------------------------------------------

    public function testProductionEnvironmentOffBypassesLicensing(): void
    {
        $this->setHost('staging.unknown-domain.tld');  // NOT in the auto-bypass list
        $this->setConfig('', '', '0');                  // toggle = No
        $this->assertTrue($this->validator->isValid());
    }

    public function testProductionEnvironmentUnsetDefaultsToYes(): void
    {
        $this->setHost('coolstore.com');
        $this->setConfig('', '', '');  // Unset
        $this->assertFalse($this->validator->isValid(), 'Unset toggle should default to production = Yes');
    }

    // -----------------------------------------------------------------
    // Per-module key (STR's own secret)
    // -----------------------------------------------------------------

    public function testValidPerModuleKeyOnProductionHost(): void
    {
        $this->setHost('coolstore.com');
        $expectedKey = $this->validator->computeKey('coolstore.com');
        $this->setConfig($expectedKey, '', '1');

        $this->assertTrue($this->validator->isValid());
    }

    public function testInvalidPerModuleKey(): void
    {
        $this->setHost('coolstore.com');
        $this->setConfig('wrong-key-here', '', '1');
        $this->assertFalse($this->validator->isValid());
    }

    public function testWwwPrefixNormalised(): void
    {
        // Key minted for coolstore.com should validate www.coolstore.com too
        $this->setHost('www.coolstore.com');
        $keyForBareDomain = $this->validator->computeKey('coolstore.com');
        $this->setConfig($keyForBareDomain, '', '1');

        $this->assertTrue($this->validator->isValid());
    }

    // -----------------------------------------------------------------
    // Bundle key (shared secret across NDE / BED / STR)
    // -----------------------------------------------------------------

    public function testValidBundleKeyActivatesModule(): void
    {
        $this->setHost('coolstore.com');
        $bundleKey = $this->validator->computeBundleKey('coolstore.com');
        $this->setConfig('', $bundleKey, '1');

        $this->assertTrue($this->validator->isValid());
    }

    public function testInvalidBundleKey(): void
    {
        $this->setHost('coolstore.com');
        $this->setConfig('', 'wrong-bundle-key', '1');
        $this->assertFalse($this->validator->isValid());
    }

    // -----------------------------------------------------------------
    // Empty-host edge case
    // -----------------------------------------------------------------

    public function testEmptyHostReturnsFalse(): void
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn('');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->scopeConfig->method('getValue')->willReturn('');

        $this->assertFalse($this->validator->isValid());
    }

    // -----------------------------------------------------------------
    // isDevHost public wrapper
    // -----------------------------------------------------------------

    public function testIsDevHostPublicWrapper(): void
    {
        $this->setHost('app.magento2.test');

        $this->assertTrue($this->validator->isDevHost('app.magento2.test'));
        $this->assertTrue($this->validator->isDevHost('staging.shop.co.uk'));
        $this->assertFalse($this->validator->isDevHost('shop.co.uk'));
    }

    public function testIsDevHostDefaultsToCurrentHost(): void
    {
        $this->setHost('localhost');
        $this->assertTrue($this->validator->isDevHost());
    }
}
