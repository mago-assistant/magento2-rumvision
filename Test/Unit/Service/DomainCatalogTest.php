<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainCatalog;
use MagoAssistant\Rumvision\Service\DomainResolver;
use MagoAssistant\Rumvision\Service\RumvisionException;
use MagoAssistant\Rumvision\Test\Unit\Fakes\FakeCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DomainCatalogTest extends TestCase
{
    private const API_RESPONSE = ['data' => [[
        'domain' => 'shop.nl',
        'default_percentile' => 80,
        'metrics' => [
            ['dimension_slug' => 'lcp', 'dimension_name' => 'LCP', 'dimensions' => ['user-country', 'device-type']],
        ],
        'filters' => [['dimension_slug' => 'hostname', 'dimension_name' => 'Hostname']],
        'url_categories' => [['id' => '019d', 'name' => 'home', 'slug' => 'home']],
    ]]];

    private const CATALOG_KEY = 'mago_rumvision_catalog_'
        . '2c70e12b7a0646f92279f427c7b38e7334d8e5389cff167a1dc30e73f826b683';

    /** @var array<int, array{string, array<string, mixed>, int|null}> */
    private array $calls = [];

    /** @var FakeCache Shared by every catalog a test builds, like the real cache across requests */
    private FakeCache $cache;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
    }

    #[Test]
    public function trimsTheCatalogToSlugsAndNamesWithALongTimeout(): void
    {
        $description = $this->catalog([self::API_RESPONSE])->describe('shop.nl');

        self::assertSame([
            'domain' => 'shop.nl',
            'default_percentile' => 80,
            'metrics' => [['slug' => 'lcp', 'name' => 'LCP']],
            'dimensions' => [['slug' => 'hostname', 'name' => 'Hostname']],
            'url_categories' => [['slug' => 'home', 'name' => 'home']],
        ], $description);
        self::assertSame(
            [['/domains', ['includeMetrics' => 'true', 'includeUrlCategories' => 'true'], 90]],
            $this->calls
        );
    }

    #[Test]
    public function fetchesTheSlowCatalogOnlyOnce(): void
    {
        $catalog = $this->catalog([self::API_RESPONSE]);

        $catalog->describe('shop.nl');
        $catalog->describe('shop.nl');

        self::assertCount(1, $this->calls);
    }

    #[Test]
    public function namesTheKnownDomainsForAnUnknownOne(): void
    {
        $this->expectException(RumvisionException::class);
        $this->expectExceptionMessage('The API key has no domain "shop.com". Known domains: shop.nl.');

        $this->catalog([self::API_RESPONSE])->describe('shop.com');
    }

    #[Test]
    public function doesNotRefetchForADomainTheKeyDoesNotHave(): void
    {
        $catalog = $this->catalog([self::API_RESPONSE]);
        $catalog->describe('shop.nl');

        $this->errorOf($catalog, 'shop.com');

        self::assertCount(1, $this->calls);
    }

    #[Test]
    public function refetchesTheCachedCatalogForADomainAddedToTheKeySince(): void
    {
        $added = self::API_RESPONSE;
        $added['data'][] = ['domain' => 'shop.be'];
        $catalog = $this->catalog([self::API_RESPONSE, $added], ['shop.nl', 'shop.be']);
        $catalog->describe('shop.nl');

        $description = $catalog->describe('shop.be');

        self::assertSame('shop.be', $description['domain']);
        self::assertCount(2, $this->calls);
    }

    #[Test]
    public function doesNotCacheAnEmptyCatalog(): void
    {
        $catalog = $this->catalog([['data' => []], self::API_RESPONSE]);
        $this->errorOf($catalog, 'shop.nl');

        $description = $catalog->describe('shop.nl');

        self::assertSame('shop.nl', $description['domain']);
        self::assertCount(2, $this->calls);
    }

    #[Test]
    public function remembersAFailedFetchForFiveMinutes(): void
    {
        $catalog = $this->catalog([new RumvisionException('RUMvision is unreachable: timeout')]);
        $this->errorOf($catalog, 'shop.nl');

        $error = $this->errorOf($catalog, 'shop.nl');

        self::assertSame('RUMvision is unreachable: timeout', $error);
        self::assertCount(1, $this->calls);
        self::assertSame(300, $this->cache->lifetimeOf(self::CATALOG_KEY . '_failure'));
    }

    private function errorOf(DomainCatalog $catalog, string $domain): ?string
    {
        try {
            $catalog->describe($domain);
        } catch (RumvisionException $e) {
            return $e->getMessage();
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>|RumvisionException> $responses One per API call, in order
     * @param string[] $apiDomains
     */
    private function catalog(array $responses, array $apiDomains = ['shop.nl']): DomainCatalog
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn('key');
        $config->method('getTimeout')->willReturn(20);
        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willReturnCallback(
            function (string $path, array $query = [], ?int $timeout = null) use (&$responses): array {
                $this->calls[] = [$path, $query, $timeout];
                $response = array_shift($responses) ?? [];
                if ($response instanceof RumvisionException) {
                    throw $response;
                }
                return $response;
            }
        );
        $resolver = $this->createStub(DomainResolver::class);
        $resolver->method('getApiDomains')->willReturn($apiDomains);

        return new DomainCatalog($config, $api, $resolver, $this->cache, new Json());
    }
}
