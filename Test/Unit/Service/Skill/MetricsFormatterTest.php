<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service\Skill;

use MagoAssistant\Rumvision\Service\Skill\MetricsFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetricsFormatterTest extends TestCase
{
    #[Test]
    public function separatesTheDomainTotalFromTheBreakdownAndAddsStatuses(): void
    {
        $result = (new MetricsFormatter())->format(['data' => ['current' => [
            'from' => '2026-08-28 00:00:00.000000+02:00',
            'to' => '2026-09-24 23:59:59.999999+02:00',
            'datasets' => [
                'ttfb' => [
                    'all' => ['is_all' => true, 'count' => 43590, 'percentiles' => ['80' => 2000.4], 'distributions' => ['good' => 50.12, 'moderate' => 30, 'poor' => 19.88]],
                    'www.shop.nl' => ['is_all' => false, 'count' => 42000, 'percentiles' => ['80' => 1790.2], 'distributions' => []],
                    'other' => ['is_other' => true, 'count' => 1, 'percentiles' => []],
                    'not_set' => ['is_not_set' => true, 'count' => 5, 'percentiles' => ['80' => 3070]],
                ],
                'cls' => [
                    'all' => ['is_all' => true, 'count' => 100, 'percentiles' => ['80' => 0.01934]],
                ],
            ],
        ]]], 'hostname');

        $ttfb = $result['periods']['current']['metrics']['ttfb'];
        self::assertSame('2026-08-28', $result['periods']['current']['from']);
        self::assertStringContainsString('Report "total" for the domain', $result['note']);
        self::assertSame(['good_up_to' => 800, 'poor_from' => 1800], $ttfb['thresholds']);
        self::assertSame(['samples' => 43590, 'p80' => 2000, 'p80_status' => 'poor', 'good_pct' => 50.1, 'moderate_pct' => 30.0, 'poor_pct' => 19.9], $ttfb['total']);
        self::assertSame([['value' => 'www.shop.nl', 'samples' => 42000, 'p80' => 1790, 'p80_status' => 'moderate']], $ttfb['by_hostname']);
        self::assertSame(0.019, $result['periods']['current']['metrics']['cls']['total']['p80']);
        self::assertSame('good', $result['periods']['current']['metrics']['cls']['total']['p80_status']);
    }

    #[Test]
    public function leavesOutTheBreakdownAndNoteWithoutADimension(): void
    {
        $result = (new MetricsFormatter())->format(['data' => ['current' => ['datasets' => [
            'custom-metric' => ['all' => ['is_all' => true, 'count' => 10, 'percentiles' => ['75' => 12.6]]],
        ]]]]);

        self::assertArrayNotHasKey('note', $result);
        self::assertSame(['total' => ['samples' => 10, 'p75' => 13]], $result['periods']['current']['metrics']['custom-metric']);
    }
}
