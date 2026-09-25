<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainResolver;
use MagoAssistant\Rumvision\Service\RumvisionException;

/**
 * Shared parameters keep one name and shape in every action: Mago merges the parameters of all actions
 * into one schema, and differing shapes for the same name made models mix them up.
 */
abstract class AbstractAction implements ActionInterface
{
    private const DEFAULT_DAYS = 28;
    private const MAX_PERIODS = 8;

    public function __construct(
        protected readonly ApiClient $apiClient,
        protected readonly Config $config,
        protected readonly DomainResolver $domainResolver
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws RumvisionException
     */
    abstract protected function run(array $params): array;

    public function execute(array $params, int $adminUserId): array
    {
        try {
            return $this->run($params);
        } catch (RumvisionException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        // Aggregated real-user performance data: no visitor-level or personal data.
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function domainParam(): array
    {
        return ['domain' => [
            'type' => 'string',
            'description' => 'Hostname as RUMvision names it (e.g. "shop.nl"). Omit to use the domain configured for '
                . 'this store.',
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function periodsParam(): array
    {
        return ['periods' => [
            'type' => 'object',
            'description' => 'Named date ranges, e.g. {"current": {"from": "2026-09-01", "to": "2026-09-24"}}; add a '
                . 'second '
                . 'name (e.g. "previous") to compare. Dates are inclusive, YYYY-MM-DD, max 8 periods. Omit for the '
                    . 'last '
                . self::DEFAULT_DAYS . ' days.',
            'additionalProperties' => [
                'type' => 'object',
                'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']],
            ],
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function metricsParam(): array
    {
        return ['metrics' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Metric slugs, e.g. ["lcp", "inp", "cls", "ttfb", "fcp"]; describe_domain lists all for a '
                . 'domain.',
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function dimensionParam(): array
    {
        return ['dimension' => [
            'type' => 'string',
            'description' => 'Optional dimension slug to break the numbers down by, e.g. "hostname", "template-type" '
                . 'or "device-type"; describe_domain lists the slugs.',
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function deviceParam(): array
    {
        return ['device' => [
            'type' => 'string',
            'enum' => ['mobile', 'desktop'],
            'description' => 'Only count mobile or desktop visits. Omit for both.',
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function percentilesParam(): array
    {
        return ['percentiles' => [
            'type' => 'array',
            'items' => ['type' => 'integer'],
            'description' => 'Percentiles to report (1-100, max 5). Omit for the domain\'s default percentile.',
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function fromParam(): array
    {
        return ['from' => [
            'type' => 'string',
            'description' => 'Earliest day, YYYY-MM-DD (get_alerts defaults to 90 days ago).',
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function limitParam(): array
    {
        return ['limit' => [
            'type' => 'integer',
            'description' => 'Max items, newest first (default 20; get_alerts max 100, list_annotations max 50).',
        ]];
    }

    /**
     * @param array<string, mixed> $params
     * @throws RumvisionException
     */
    protected function domain(array $params): string
    {
        $domain = strtolower(trim((string)($params['domain'] ?? '')));
        if ($domain !== '') {
            $host = parse_url(str_contains($domain, '://') ? $domain : 'https://' . $domain, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : $domain;
        }

        $default = $this->domainResolver->getDefaultDomain();
        if ($default !== '') {
            return $default;
        }
        $inUse = array_keys($this->domainResolver->getDomains());
        if ($inUse === []) {
            // Name the key's domains right away: models tended to give up here instead of calling list_domains.
            $available = $this->domainResolver->getApiDomains();
            throw new RumvisionException(
                'No RUMvision domain is linked to this store. Call again with "domain" set to the one the user means'
                . ($available !== [] ? ', from: ' . implode(', ', $available) : '; list_domains shows them') . '.'
            );
        }
        throw new RumvisionException(
            'This store uses several RUMvision domains (' . implode(', ', $inUse) . '); pass "domain".'
        );
    }

    /**
     * Accepts what models actually send: a map of named ranges, a single {from, to}, a list of ranges or "7d".
     *
     * @param array<string, mixed> $params
     * @return array<string, array{from: string, to: string}>
     * @throws RumvisionException
     */
    protected function periods(array $params): array
    {
        $periods = $params['periods'] ?? null;
        if (is_string($periods) && preg_match('/^(\d{1,3})\s*d(ays?)?$/i', trim($periods), $match)) {
            return ['current' => $this->lastDays((int)$match[1])];
        }
        if (!is_array($periods) || $periods === []) {
            return ['current' => $this->lastDays(self::DEFAULT_DAYS)];
        }
        if (isset($periods['from']) || isset($periods['to'])) {
            $periods = ['current' => $periods];
        } elseif (array_is_list($periods)) {
            $named = [];
            foreach ($periods as $index => $period) {
                $named['period_' . ($index + 1)] = $period;
            }
            $periods = $named;
        }
        if (count($periods) > self::MAX_PERIODS) {
            throw new RumvisionException('At most ' . self::MAX_PERIODS . ' periods can be compared at once.');
        }

        $normalized = [];
        foreach ($periods as $name => $period) {
            $from = is_array($period) ? (string)($period['from'] ?? '') : '';
            $to = is_array($period) ? (string)($period['to'] ?? $from) : '';
            if (!$this->isDate($from) || !$this->isDate($to)) {
                throw new RumvisionException(sprintf('Period "%s" needs "from" and "to" as YYYY-MM-DD.', $name));
            }
            $normalized[(string)$name] = $from <= $to ? ['from' => $from, 'to' => $to] : ['from' => $to, 'to' => $from];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{device-type: string[]}
     */
    protected function filters(array $params): array
    {
        $device = $params['device'] ?? null;

        // The API requires device-type even without a device filter (422 otherwise), so "both" is sent explicitly.
        return ['device-type' => in_array($device, ['mobile', 'desktop'], true) ? [$device] : ['mobile', 'desktop']];
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]|null
     */
    protected function percentiles(array $params): ?array
    {
        $values = array_values(array_unique(array_filter(
            array_map('intval', (array)($params['percentiles'] ?? [])),
            static fn (int $value): bool => $value >= 1 && $value <= 100
        )));

        return $values !== [] ? array_slice($values, 0, 5) : null;
    }

    /**
     * Keeps tool output within Mago's per-result token cap: long texts are cut, the gist stays.
     */
    protected function shorten(mixed $text, int $length = 200): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', (string)$text));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }

    /**
     * @return array{from: string, to: string}
     */
    private function lastDays(int $days): array
    {
        // Up to yesterday: today's data is still coming in.
        $days = max(1, min($days, 365));

        return ['from' => gmdate('Y-m-d', time() - $days * 86400), 'to' => gmdate('Y-m-d', time() - 86400)];
    }

    private function isDate(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
    }
}
