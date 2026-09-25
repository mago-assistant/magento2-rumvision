<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainCatalog;
use MagoAssistant\Rumvision\Service\RumvisionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainCatalogTest extends TestCase
{
    private const API_RESPONSE = ['data' => [[
        'domain' => 'shop.nl',
        'default_percentile' => 80,
        'metrics' => [['dimension_slug' => 'lcp', 'dimension_name' => 'LCP', 'dimensions' => ['user-country', 'device-type']]],
        'filters' => [['dimension_slug' => 'hostname', 'dimension_name' => 'Hostname']],
        'url_categories' => [['id' => '019d', 'name' => 'home', 'slug' => 'home']],
    ]]];

    /** @var array<string, string> */
    private array $cacheStore = [];

    #[Test]
    public function trimsTheCatalogToSlugsAndNamesWithALongTimeout(): void
    {
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::once())->method('get')
            ->with('/domains', ['includeMetrics' => 'true', 'includeUrlCategories' => 'true'], 90)
            ->willReturn(self::API_RESPONSE);

        self::assertSame([
            'domain' => 'shop.nl',
            'default_percentile' => 80,
            'metrics' => [['slug' => 'lcp', 'name' => 'LCP']],
            'dimensions' => [['slug' => 'hostname', 'name' => 'Hostname']],
            'url_categories' => [['slug' => 'home', 'name' => 'home']],
        ], $this->catalog($api)->describe('shop.nl'));
    }

    #[Test]
    public function fetchesTheSlowCatalogOnlyOnce(): void
    {
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::once())->method('get')->willReturn(self::API_RESPONSE);
        $catalog = $this->catalog($api);

        $catalog->describe('shop.nl');
        $catalog->describe('shop.nl');
    }

    #[Test]
    public function namesTheKnownDomainsForAnUnknownOne(): void
    {
        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willReturn(self::API_RESPONSE);

        $this->expectException(RumvisionException::class);
        $this->expectExceptionMessage('The API key has no domain "shop.com". Known domains: shop.nl.');
        $this->catalog($api)->describe('shop.com');
    }

    private function catalog(ApiClient $api): DomainCatalog
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn('key');
        $config->method('getTimeout')->willReturn(20);
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $id) => $this->cacheStore[$id] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $id): bool {
            $this->cacheStore[$id] = $data;
            return true;
        });

        return new DomainCatalog($config, $api, $cache, new Json());
    }
}
