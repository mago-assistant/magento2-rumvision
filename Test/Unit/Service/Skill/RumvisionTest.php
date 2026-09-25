<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service\Skill;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainCatalog;
use MagoAssistant\Rumvision\Service\DomainResolver;
use MagoAssistant\Rumvision\Service\Skill\Action;
use MagoAssistant\Rumvision\Service\Skill\MetricsFormatter;
use MagoAssistant\Rumvision\Service\Skill\Rumvision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RumvisionTest extends TestCase
{
    #[Test]
    public function sharedParametersHaveOneShapeInEveryAction(): void
    {
        $seen = [];
        foreach ($this->actions($this->createStub(DomainResolver::class)) as $action) {
            foreach ($action->getParameterSchema() as $name => $schema) {
                $seen[$name][] = json_encode($schema);
            }
        }

        foreach ($seen as $name => $schemas) {
            self::assertCount(1, array_unique($schemas), sprintf('parameter "%s" differs between actions', $name));
        }
    }

    #[Test]
    public function isAReadOnlySkillNamedRumvisionThatExplainsWhichHostnamesADomainMeasures(): void
    {
        $resolver = $this->createStub(DomainResolver::class);
        $resolver->method('getDomains')->willReturn(['shop.nl' => [
            ['store' => 'Shop NL', 'website' => 'Main', 'host' => 'www.shop.nl', 'path' => '/'],
            ['store' => 'Shop EN', 'website' => 'Main', 'host' => 'www.shop.nl', 'path' => '/en'],
            ['store' => 'Shop DE', 'website' => 'DE', 'host' => 'www.winkel.de', 'path' => '/'],
        ]]);
        $skill = new Rumvision($this->createStub(AuthorizationInterface::class), $resolver, $this->actions($resolver));

        self::assertSame('rumvision', $skill->getName());
        self::assertTrue($skill->isReadOnly());
        self::assertSame(
            ['list_domains', 'describe_domain', 'get_metrics', 'get_ux_scores', 'get_ux_timeline', 'get_health_checks', 'get_alerts', 'list_annotations'],
            $skill->getParameterSchema()['properties']['action']['enum']
        );
        self::assertStringContainsString(
            'RUMvision domain shop.nl measures: www.shop.nl (Shop NL, Shop EN under /en); www.winkel.de (Shop DE).',
            $skill->getInstructions()
        );
        self::assertStringContainsString('break down by the dimension "hostname"', $skill->getInstructions());
    }

    /**
     * @return array<string, Action\AbstractAction>
     */
    private function actions(DomainResolver $resolver): array
    {
        $api = $this->createStub(ApiClient::class);
        $config = $this->createStub(Config::class);

        return [
            'list_domains' => new Action\ListDomains($api, $config, $resolver),
            'describe_domain' => new Action\DescribeDomain($api, $config, $resolver, $this->createStub(DomainCatalog::class)),
            'get_metrics' => new Action\GetMetrics($api, $config, $resolver, new MetricsFormatter()),
            'get_ux_scores' => new Action\GetUxScores($api, $config, $resolver),
            'get_ux_timeline' => new Action\GetUxTimeline($api, $config, $resolver),
            'get_health_checks' => new Action\GetHealthChecks($api, $config, $resolver),
            'get_alerts' => new Action\GetAlerts($api, $config, $resolver),
            'list_annotations' => new Action\ListAnnotations($api, $config, $resolver),
        ];
    }
}
