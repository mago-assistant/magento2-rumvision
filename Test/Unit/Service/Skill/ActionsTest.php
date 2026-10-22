<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service\Skill;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainCatalog;
use MagoAssistant\Rumvision\Service\DomainResolver;
use MagoAssistant\Rumvision\Service\RumvisionException;
use MagoAssistant\Rumvision\Service\Skill\Action\DescribeDomain;
use MagoAssistant\Rumvision\Service\Skill\Action\GetAlerts;
use MagoAssistant\Rumvision\Service\Skill\Action\GetHealthChecks;
use MagoAssistant\Rumvision\Service\Skill\Action\GetMetrics;
use MagoAssistant\Rumvision\Service\Skill\Action\GetUxScores;
use MagoAssistant\Rumvision\Service\Skill\Action\GetUxTimeline;
use MagoAssistant\Rumvision\Service\Skill\Action\ListAnnotations;
use MagoAssistant\Rumvision\Service\Skill\Action\ListDomains;
use MagoAssistant\Rumvision\Service\Skill\MetricsFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ActionsTest extends TestCase
{
    /** @var array<int, array{string, string, array<string, mixed>}> */
    private array $calls = [];

    #[Test]
    public function queriesMetricsForTheConfiguredDomainWithNamedPeriodsAndDevice(): void
    {
        $this->action(GetMetrics::class)->execute([
            'metrics' => ['lcp', 'inp'],
            'periods' => ['current' => ['from' => '2026-09-01', 'to' => '2026-09-24']],
            'device' => 'mobile',
            'percentiles' => [75, 0, 75, 101],
        ], 1);

        [$method, $path, $body] = $this->calls[0];
        self::assertSame('POST', $method);
        self::assertSame('/data/distribution/shop.nl', $path);
        self::assertSame(['lcp', 'inp'], $body['metrics']);
        self::assertSame(['current' => ['from' => '2026-09-01', 'to' => '2026-09-24']], $body['periods']);
        self::assertSame(['device-type' => ['mobile']], $body['filters']);
        self::assertSame([75], $body['percentiles']);
    }

    #[Test]
    public function breaksMetricsDownByADimensionAndCleansAPastedUrl(): void
    {
        $this->action(GetMetrics::class)->execute(
            ['domain' => 'https://www.Other.nl/', 'dimension' => 'url-category'],
            1
        );

        self::assertSame('/data/distribution/www.other.nl/url-category', $this->calls[0][1]);
        self::assertSame(
            ['device-type' => ['mobile', 'desktop']],
            $this->calls[0][2]['filters'],
            'the API requires device-type'
        );
    }

    #[Test]
    public function refusesToCallTheApiWhileSwitchedOff(): void
    {
        $result = $this->action(GetMetrics::class, isEnabled: false)->execute(['domain' => 'shop.nl'], 1);

        self::assertSame(
            ['error' => 'RUMvision is switched off: enable it and enter an API key under Stores > Configuration > '
                . 'Mago Assistant > RUMvision.'],
            $result
        );
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function refusesADomainWithoutAHostname(): void
    {
        $result = $this->action(GetMetrics::class)->execute(['domain' => 'https://shop.nl:abc'], 1);

        self::assertSame(['error' => '"https://shop.nl:abc" is not a domain; list_domains shows them.'], $result);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function queriesUxScoresPerDimensionForTheDevice(): void
    {
        $this->action(GetUxScores::class)->execute([
            'dimension' => 'template-type',
            'periods' => ['current' => ['from' => '2026-09-01', 'to' => '2026-09-24']],
            'device' => 'desktop',
        ], 1);

        self::assertSame(['POST', '/data/ux-scores/shop.nl/template-type', [
            'periods' => ['current' => ['from' => '2026-09-01', 'to' => '2026-09-24']],
            'filters' => ['device-type' => ['desktop']],
        ]], $this->calls[0]);
    }

    #[Test]
    public function queriesTheUxTimelineForTheDomain(): void
    {
        $this->action(GetUxTimeline::class)->execute([
            'domain' => 'shop.be',
            'periods' => ['current' => ['from' => '2026-09-01', 'to' => '2026-09-24']],
        ], 1);

        self::assertSame(['POST', '/data/ux-scores-timeline/shop.be', [
            'periods' => ['current' => ['from' => '2026-09-01', 'to' => '2026-09-24']],
            'interval' => ['interval' => 1, 'unit' => 'd'],
            'filters' => ['device-type' => ['mobile', 'desktop']],
        ]], $this->calls[0]);
    }

    #[Test]
    public function describesTheStoresDomainFromTheCatalog(): void
    {
        $catalog = $this->createStub(DomainCatalog::class);
        $catalog->method('describe')->willReturnCallback(static fn (string $domain): array => ['domain' => $domain]);
        $action = new DescribeDomain($this->createStub(ApiClient::class), $this->config(), $this->resolver(), $catalog);

        $result = $action->execute([], 1);

        self::assertSame(['domain' => 'shop.nl'], $result);
    }

    #[Test]
    public function normalisesThePeriodShapesModelsSend(): void
    {
        $action = $this->action(GetMetrics::class);

        $action->execute(['periods' => ['from' => '2026-09-01', 'to' => '2026-09-24']], 1);
        $action->execute(['periods' => [
            ['from' => '2026-09-10', 'to' => '2026-09-01'],
            ['from' => '2026-08-01', 'to' => '2026-08-09'],
        ]], 1);
        $action->execute(['periods' => '7d'], 1);
        $action->execute([], 1);

        self::assertSame(['current' => ['from' => '2026-09-01', 'to' => '2026-09-24']], $this->calls[0][2]['periods']);
        self::assertSame(
            [
                'period_1' => ['from' => '2026-09-01', 'to' => '2026-09-10'],
                'period_2' => ['from' => '2026-08-01', 'to' => '2026-08-09'],
            ],
            $this->calls[1][2]['periods']
        );
        self::assertSame(gmdate('Y-m-d', strtotime('-7 days')), $this->calls[2][2]['periods']['current']['from']);
        self::assertSame(gmdate('Y-m-d', strtotime('-1 day')), $this->calls[3][2]['periods']['current']['to']);
    }

    #[Test]
    public function reportsAnInvalidPeriodWithoutCallingTheApi(): void
    {
        $result = $this->action(GetMetrics::class)->execute(['periods' => ['current' => ['from' => 'last week']]], 1);

        self::assertSame(['error' => 'Period "current" needs "from" and "to" as YYYY-MM-DD.'], $result);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function asksForTheDomainWhenTheStoreHasSeveral(): void
    {
        $result = $this->action(GetMetrics::class, '', ['shop.nl' => [], 'shop.be' => []])->execute([], 1);

        self::assertSame(
            ['error' => 'This store uses several RUMvision domains (shop.nl, shop.be); pass "domain".'],
            $result
        );
    }

    #[Test]
    public function namesTheKeysDomainsWhenNoneIsLinkedToTheStore(): void
    {
        $result = $this->action(GetMetrics::class, '', [])->execute([], 1);

        self::assertSame(
            ['error' => 'No RUMvision domain is linked to this store. Call again with "domain" set to the one the user '
                . 'means, from: other.nl, shop.nl.'],
            $result
        );
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function parsesTheTimelineInterval(): void
    {
        $action = $this->action(GetUxTimeline::class);

        $action->execute(['interval' => '1w'], 1);
        $invalid = $action->execute(['interval' => '3d'], 1);

        self::assertSame(['interval' => 1, 'unit' => 'w'], $this->calls[0][2]['interval']);
        self::assertArrayHasKey('error', $invalid);
    }

    #[Test]
    public function passesOnlyKnownHealthCheckStatusesAndCapsAlerts(): void
    {
        $this->action(GetHealthChecks::class)->execute(['status' => ['open', 'bogus']], 1);
        $this->action(GetAlerts::class)->execute(['limit' => 1000, 'from' => '2026-09-01', 'to' => 'soon'], 1);

        self::assertSame(['GET', '/health-checks/shop.nl', ['status' => ['open']]], $this->calls[0]);
        self::assertSame(['GET', '/alerts/shop.nl', ['limit' => 100, 'from' => '2026-09-01']], $this->calls[1]);
    }

    #[Test]
    public function listsDomainsCheaplyWithTheirStoreViews(): void
    {
        $result = $this->action(ListDomains::class)->execute([], 1);

        self::assertSame(['GET', '/domains', []], $this->calls[0]);
        self::assertSame(['shop.nl' => []], $result['store_views_by_domain']);
    }

    #[Test]
    public function turnsApiErrorsIntoAnErrorResult(): void
    {
        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willThrowException(new RumvisionException('RUMvision rate limit reached.'));

        $action = new GetHealthChecks($api, $this->config(), $this->resolver());

        self::assertSame(['error' => 'RUMvision rate limit reached.'], $action->execute([], 1));
    }

    #[Test]
    public function returnsOnlyOpenFindingsWithShortDescriptionsByDefault(): void
    {
        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willReturn(['data' => [[
            'slug' => 'lcp-priority',
            'title' => 'LCP priority',
            'status' => 'open',
            'description' => 'Long check description the assistant does not need',
            'remediation_steps' => [['title' => 'Add fetchpriority', 'description' => 'Long text']],
            'todos' => [
                [
                    'title' => 'Open one',
                    'status' => 'open',
                    'filter_value' => 'pdp',
                    'device_type' => 'mobile',
                    'description' => str_repeat('x', 300),
                ],
                ['title' => 'Fixed one', 'status' => 'fixed'],
            ],
        ]]]);
        $action = new GetHealthChecks($api, $this->config(), $this->resolver());

        $check = $action->execute([], 1)['health_checks'][0];
        $withHistory = $action->execute(['include_closed' => true], 1)['health_checks'][0];

        self::assertSame(['Add fetchpriority'], $check['remediation']);
        self::assertSame(['Open one'], array_column($check['findings'], 'title'));
        self::assertSame('pdp', $check['findings'][0]['segment']);
        self::assertSame(200, mb_strlen($check['findings'][0]['description']));
        self::assertArrayNotHasKey('description', $check);
        self::assertCount(2, $withHistory['findings']);
    }

    #[Test]
    public function returnsTheNewestAnnotationsTrimmed(): void
    {
        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willReturn(['data' => [
            ['title' => 'Old', 'impact_at' => '2026-01-01T10:00:00+00:00'],
            [
                'title' => 'New',
                'impact_at' => '2026-09-20T10:00:00+00:00',
                'category' => ['name' => 'Caching'],
                'url_categories' => [['name' => 'pdp']],
            ],
            ['title' => 'Middle', 'impact_at' => '2026-05-01T10:00:00+00:00'],
        ]]);
        $action = new ListAnnotations($api, $this->config(), $this->resolver());

        $result = $action->execute(['limit' => 2], 1);
        $recent = $action->execute(['from' => '2026-04-01'], 1);

        self::assertSame(3, $result['total']);
        self::assertSame(['New', 'Middle'], array_column($result['annotations'], 'title'));
        self::assertSame(
            [
                'title' => 'New',
                'impact_at' => '2026-09-20T10:00:00+00:00',
                'category' => 'Caching',
                'templates' => ['pdp'],
            ],
            $result['annotations'][0]
        );
        self::assertSame(2, $recent['total']);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, array<int, mixed>> $inUse
     * @return T
     */
    private function action(
        string $class,
        string $default = 'shop.nl',
        array $inUse = ['shop.nl' => []],
        bool $isEnabled = true
    ): object {
        $api = $this->createStub(ApiClient::class);
        $api->method('get')->willReturnCallback(function (string $path, array $query = []): array {
            $this->calls[] = ['GET', $path, $query];
            return ['data' => []];
        });
        $api->method('post')->willReturnCallback(function (string $path, array $body): array {
            $this->calls[] = ['POST', $path, $body];
            return [];
        });

        $args = [$api, $this->config($isEnabled), $this->resolver($default, $inUse)];
        if ($class === GetMetrics::class) {
            $args[] = new MetricsFormatter();
        }

        return new $class(...$args);
    }

    private function config(bool $isEnabled = true): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($isEnabled);
        $scopeConfig->method('getValue')->willReturn('encrypted-key');
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('key');

        return new Config($scopeConfig, $encryptor, $this->createStub(StoreManagerInterface::class));
    }

    /**
     * @param array<string, array<int, mixed>> $inUse
     */
    private function resolver(string $default = 'shop.nl', array $inUse = ['shop.nl' => []]): DomainResolver
    {
        $resolver = $this->createStub(DomainResolver::class);
        $resolver->method('getDefaultDomain')->willReturn($default);
        $resolver->method('getDomains')->willReturn($inUse);
        $resolver->method('getApiDomains')->willReturn(['other.nl', 'shop.nl']);

        return $resolver;
    }
}
