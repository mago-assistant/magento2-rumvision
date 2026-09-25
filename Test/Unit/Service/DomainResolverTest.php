<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainResolverTest extends TestCase
{
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

    /**
     * @param string[] $apiDomains
     * @param array<string, string> $configured website id ('' = default) => domain
     * @param array<int, array{int, string, string}> $stores website id, name, base URL
     */
    private function resolver(array $apiDomains, array $configured = [], array $stores = [], string $apiKey = 'key'): DomainResolver
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn($apiKey);
        $config->method('getConfiguredDomain')->willReturnCallback(
            static fn (?int $websiteId = null): string => $configured[$websiteId === null ? '' : (string)$websiteId] ?? ''
        );

        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willReturn(['data' => array_map(static fn (string $d): array => ['domain' => $d], $apiDomains)]);

        $storeModels = [];
        foreach ($stores as [$websiteId, $name, $url]) {
            $store = $this->createStub(Store::class);
            $store->method('isActive')->willReturn(true);
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

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        return new DomainResolver($config, $api, $storeManager, $cache, new Json());
    }
}
