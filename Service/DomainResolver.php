<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Rumvision\Model\Config;

/**
 * RUMvision measures per tracking snippet, not per URL: one RUMvision domain usually collects several
 * hostnames (other store views, other languages, even a staging host). This maps store views to their
 * RUMvision domain and hostname.
 */
class DomainResolver
{
    private const CACHE_TAG = 'MAGO_RUMVISION';
    private const CACHE_LIFETIME = 3600;
    private const FAILURE_CACHE_LIFETIME = 300;

    /** @var array<string, array<int, array{store: string, website: string, host: string, path: string}>>|null */
    private ?array $domains = null;

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * Domains the API key can read, cached; [] when the key is missing or the API fails
     *
     * @return string[]
     */
    public function getApiDomains(): array
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            return [];
        }
        $cacheKey = 'mago_rumvision_domains_' . hash('sha256', $apiKey);
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $domains = $this->json->unserialize($cached);
            return is_array($domains) ? array_map('strval', $domains) : [];
        }

        try {
            $domains = array_values(array_filter(array_map(
                static fn ($row): string => is_array($row) ? (string)($row['domain'] ?? '') : '',
                (array)($this->apiClient->get('/domains')['data'] ?? [])
            )));
            $lifetime = self::CACHE_LIFETIME;
        } catch (RumvisionException) {
            $domains = [];
            $lifetime = self::FAILURE_CACHE_LIFETIME;
        }
        // Not tagged as config: config saves and cache watchers would drop it; a new key gets a new cache key.
        $this->cache->save((string)$this->json->serialize($domains), $cacheKey, [self::CACHE_TAG], $lifetime);

        return $domains;
    }

    /**
     * Store views grouped by the RUMvision domain that measures them
     *
     * @return array<string, array<int, array{store: string, website: string, host: string, path: string}>>
     */
    public function getDomains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $domains = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $url = (string)$store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $websiteId = (int)$store->getWebsiteId();
            $domain = $this->domainFor($websiteId, $host);
            if ($domain === '') {
                continue;
            }
            $domains[$domain][] = [
                'store' => (string)$store->getName(),
                'website' => (string)$this->storeManager->getWebsite($websiteId)->getName(),
                'host' => $host,
                'path' => rtrim((string)parse_url($url, PHP_URL_PATH), '/') ?: '/',
            ];
        }

        return $this->domains = $domains;
    }

    /**
     * The domain for a request without one: the only domain in use, or '' when there are several or none
     */
    public function getDefaultDomain(): string
    {
        $domains = array_keys($this->getDomains());
        if (count($domains) === 1) {
            return (string)$domains[0];
        }
        if ($domains === []) {
            $apiDomains = $this->getApiDomains();
            return count($apiDomains) === 1 ? $apiDomains[0] : '';
        }
        return '';
    }

    /**
     * Admin choice for the website, then for the default scope, then the API domain matching the host
     */
    public function domainFor(int $websiteId, string $host): string
    {
        $configured = $this->config->getConfiguredDomain($websiteId);
        if ($configured === '') {
            $configured = $this->config->getConfiguredDomain();
        }
        if ($configured !== '') {
            return $configured;
        }

        return $this->matchHost($host);
    }

    /**
     * shop.nl matches the hosts shop.nl and www.shop.nl (any subdomain), the longest match wins
     */
    public function matchHost(string $host): string
    {
        $match = '';
        foreach ($this->getApiDomains() as $domain) {
            $isMatch = $host === $domain || str_ends_with($host, '.' . $domain);
            if ($isMatch && strlen($domain) > strlen($match)) {
                $match = $domain;
            }
        }
        return $match;
    }
}
