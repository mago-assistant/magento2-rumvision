<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Rumvision\Model\Config;

/**
 * Metric, dimension and template slugs per domain. RUMvision only returns them for all domains of the
 * key at once, with every metric's full dimension list: for an organisation-wide key that took ~50s and
 * 2 MB. It is fetched once with a long timeout, trimmed to slugs and names, and cached for a day.
 */
class DomainCatalog
{
    private const CACHE_TAG = 'MAGO_RUMVISION';
    private const CACHE_LIFETIME = 86400;
    private const FAILURE_CACHE_LIFETIME = 300;
    private const TIMEOUT = 90;

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly DomainResolver $domainResolver,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * @return array<string, mixed> domain, default_percentile, metrics, dimensions, url_categories (slug + name)
     * @throws RumvisionException
     */
    public function describe(string $domain): array
    {
        $catalog = $this->cachedCatalog();
        if ($catalog === null || $this->isAddedSinceCached($catalog, $domain)) {
            $catalog = $this->fetchCatalog();
        }
        if (!isset($catalog[$domain])) {
            throw new RumvisionException(sprintf(
                'The API key has no domain "%s". Known domains: %s.',
                $domain,
                implode(', ', array_keys($catalog))
            ));
        }

        return $catalog[$domain];
    }

    /**
     * @return array<string, array<string, mixed>>|null Keyed by domain
     */
    private function cachedCatalog(): ?array
    {
        $cached = (string)$this->cache->load($this->cacheKey());
        if ($cached === '') {
            return null;
        }
        $catalog = $this->json->unserialize($cached);

        return is_array($catalog) ? $catalog : null;
    }

    /**
     * The cheap domain list knows a domain the day-old catalog does not: the key gained it since.
     *
     * @param array<string, array<string, mixed>> $catalog
     */
    private function isAddedSinceCached(array $catalog, string $domain): bool
    {
        return !isset($catalog[$domain]) && in_array($domain, $this->domainResolver->getApiDomains(), true);
    }

    /**
     * @return array<string, array<string, mixed>> Keyed by domain
     * @throws RumvisionException
     */
    private function fetchCatalog(): array
    {
        $failureKey = $this->cacheKey() . '_failure';
        $failure = (string)$this->cache->load($failureKey);
        if ($failure !== '') {
            throw new RumvisionException($failure);
        }

        try {
            $rows = $this->apiClient->get(
                '/domains',
                ['includeMetrics' => 'true', 'includeUrlCategories' => 'true'],
                max(self::TIMEOUT, $this->config->getTimeout())
            )['data'] ?? [];
        } catch (RumvisionException $e) {
            // The call can take the full timeout: without this, every describe_domain would wait for it again.
            $this->cache->save($e->getMessage(), $failureKey, [self::CACHE_TAG], self::FAILURE_CACHE_LIFETIME);
            throw $e;
        }

        $catalog = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['domain'] ?? null)) {
                continue;
            }
            $catalog[$row['domain']] = [
                'domain' => $row['domain'],
                'default_percentile' => $row['default_percentile'] ?? null,
                'metrics' => $this->slugs($row['metrics'] ?? [], 'dimension_slug', 'dimension_name'),
                'dimensions' => $this->slugs($row['filters'] ?? [], 'dimension_slug', 'dimension_name'),
                'url_categories' => $this->slugs($row['url_categories'] ?? [], 'slug', 'name'),
            ];
        }
        if ($catalog !== []) {
            // Not tagged as config: config saves and cache watchers would drop it; a new key gets a new cache key.
            $this->cache->save(
                (string)$this->json->serialize($catalog),
                $this->cacheKey(),
                [self::CACHE_TAG],
                self::CACHE_LIFETIME
            );
        }

        return $catalog;
    }

    private function cacheKey(): string
    {
        return 'mago_rumvision_catalog_' . hash('sha256', $this->config->getApiKey());
    }

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    private function slugs(mixed $items, string $slugKey, string $nameKey): array
    {
        $slugs = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item[$slugKey] ?? null)) {
                $slugs[] = ['slug' => $item[$slugKey], 'name' => (string)($item[$nameKey] ?? $item[$slugKey])];
            }
        }
        return $slugs;
    }
}
