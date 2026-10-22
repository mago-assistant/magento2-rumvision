<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainResolver;
use MagoAssistant\Rumvision\Service\RumvisionException;
use MagoAssistant\Rumvision\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Rumvision\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DomainResolverTest extends TestCase
{
    private const DOMAINS_KEY = 'mago_rumvision_domains_'
        . '2c70e12b7a0646f92279f427c7b38e7334d8e5389cff167a1dc30e73f826b683';

    /** @var int Requests that reached the RUMvision API */
    private int $apiCalls = 0;

    /** @var FakeCache Shared by every resolver a test builds, like the real cache across requests */
    private FakeCache $cache;

    /** @var FakeLogger */
    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
        $this->logger = new FakeLogger();
    }

    #[Test]
    public function matchesAHostToTheLongestApiDomain(): void
    {
        $resolver = $this->resolver(['shop.nl', 'b2b.shop.nl', 'other.nl']);

        self::assertSame('shop.nl', $resolver->matchHost('www.shop.nl'));
        self::assertSame('shop.nl', $resolver->matchHost('shop.nl'));
        self::assertSame('b2b.shop.nl', $resolver->matchHost('b2b.shop.nl'));
        self::assertSame('', $resolver->matchHost('www.winkel.de'));
        self::assertSame('', $resolver->matchHost('notshop.nl'), 'a suffix without dot is another domain');
    }

    #[Test]
    public function groupsStoreViewsOfOtherHostnamesUnderTheConfiguredDomain(): void
    {
        $resolver = $this->resolver(['shop.nl'], ['' => 'shop.nl'], [
            [1, 'Shop NL', 'https://www.shop.nl/'],
            [1, 'Shop EN', 'https://www.shop.nl/en/'],
            [2, 'Shop DE', 'https://www.winkel.de/'],
        ]);

        $domains = $resolver->getDomains();

        self::assertSame(['shop.nl'], array_keys($domains));
        self::assertSame(['www.shop.nl', 'www.shop.nl', 'www.winkel.de'], array_column($domains['shop.nl'], 'host'));
        self::assertSame(['/', '/en', '/'], array_column($domains['shop.nl'], 'path'));
        self::assertSame('shop.nl', $resolver->getDefaultDomain());
    }

    #[Test]
    public function usesAWebsiteDomainAndOtherwiseTheMatchingApiDomain(): void
    {
        $resolver = $this->resolver(['shop.nl', 'shop.be'], ['2' => 'shop.be'], [
            [1, 'NL', 'https://www.shop.nl/'],
            [2, 'BE', 'https://www.winkel.be/'],
        ]);

        self::assertSame(['shop.nl', 'shop.be'], array_keys($resolver->getDomains()));
        self::assertSame('', $resolver->getDefaultDomain(), 'two domains in use: the assistant must pick one');
    }

    #[Test]
    public function returnsNoApiDomainsWithoutAKey(): void
    {
        self::assertSame([], $this->resolver(['shop.nl'], [], [], '')->getApiDomains());
    }

    #[Test]
    public function treatsAFailingApiAsNoDomainsForFiveMinutesAndLogsWhy(): void
    {
        $resolver = $this->resolver(new RumvisionException('RUMvision rejected the API key.'));

        $domains = $resolver->getApiDomains();

        self::assertSame([], $domains);
        self::assertSame(300, $this->cache->lifetimeOf(self::DOMAINS_KEY));
        self::assertSame(
            ['RUMvision domains could not be loaded: RUMvision rejected the API key.'],
            $this->logger->getMessages()
        );
    }

    #[Test]
    public function readsTheApiDomainsFromTheCacheOnTheNextRequest(): void
    {
        $this->resolver(['shop.nl'])->getApiDomains();

        $domains = $this->resolver(['other.nl'])->getApiDomains();

        self::assertSame(['shop.nl'], $domains);
        self::assertSame(1, $this->apiCalls);
        self::assertSame(3600, $this->cache->lifetimeOf(self::DOMAINS_KEY));
    }

    #[Test]
    public function defaultsToTheKeysOnlyDomainWhenNoStoreViewMatches(): void
    {
        self::assertSame('shop.nl', $this->resolver(['shop.nl'])->getDefaultDomain());
    }

    #[Test]
    public function leavesTheDomainOpenWhenTheKeyHasSeveralAndNoStoreViewMatches(): void
    {
        self::assertSame('', $this->resolver(['shop.nl', 'shop.be'])->getDefaultDomain());
    }

    /**
     * @param string[]|RumvisionException $apiDomains
     * @param array<int|string, string> $configured website id ('' = default) => domain
     * @param array<int, array{int, string, string}> $stores website id, name, base URL
     */
    private function resolver(
        array|RumvisionException $apiDomains,
        array $configured = [],
        array $stores = [],
        string $apiKey = 'key'
    ): DomainResolver {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn($apiKey);
        $config->method('getConfiguredDomain')->willReturnCallback(
            static fn (?int $websiteId = null): string =>
                $configured[$websiteId === null ? '' : (string)$websiteId] ?? ''
        );

        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willReturnCallback(function () use ($apiDomains): array {
            $this->apiCalls++;
            if ($apiDomains instanceof RumvisionException) {
                throw $apiDomains;
            }
            return ['data' => array_map(static fn (string $d): array => ['domain' => $d], $apiDomains)];
        });

        $storeModels = [];
        foreach ($stores as [$websiteId, $name, $url]) {
            $store = $this->createStub(Store::class);
            $store->method('getIsActive')->willReturn(1);
            $store->method('getBaseUrl')->willReturn($url);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $store->method('getName')->willReturn($name);
            $storeModels[] = $store;
        }
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getName')->willReturn('Website');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($storeModels);
        $storeManager->method('getWebsite')->willReturn($website);

        return new DomainResolver($config, $api, $storeManager, $this->cache, new Json(), $this->logger);
    }
}
