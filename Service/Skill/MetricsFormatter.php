<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill;

/**
 * Reshapes a distribution response so the domain total and the per-dimension rows cannot be confused
 * (models reported a hostname row as the domain), and adds good/moderate/poor, which the API leaves out.
 */
class MetricsFormatter
{
    // Core Web Vitals thresholds (web.dev), the ones RUMvision uses for these metrics: [good up to, poor from].
    private const THRESHOLDS = [
        'lcp' => [2500, 4000],
        'inp' => [200, 500],
        'cls' => [0.1, 0.25],
        'fcp' => [1800, 3000],
        'ttfb' => [800, 1800],
    ];

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function format(array $response, string $dimension = ''): array
    {
        $periods = [];
        foreach ((array)($response['data'] ?? []) as $name => $period) {
            if (!is_array($period)) {
                continue;
            }
            $metrics = [];
            foreach ((array)($period['datasets'] ?? []) as $metric => $rows) {
                $metrics[(string)$metric] = $this->metric((string)$metric, (array)$rows, $dimension);
            }
            $periods[(string)$name] = [
                'from' => substr((string)($period['from'] ?? ''), 0, 10),
                'to' => substr((string)($period['to'] ?? ''), 0, 10),
                'metrics' => $metrics,
            ];
        }

        return array_filter([
            'note' => $dimension !== ''
                ? sprintf(
                    '"total" is the whole domain; "by_%s" splits it per value. Report "total" for the domain.',
                    $dimension
                )
                : null,
            'periods' => $periods,
        ]);
    }

    /**
     * @param array<string, mixed> $rows
     * @return array<string, mixed>
     */
    private function metric(string $metric, array $rows, string $dimension): array
    {
        $result = [];
        if (isset(self::THRESHOLDS[$metric])) {
            [$good, $poor] = self::THRESHOLDS[$metric];
            $result['thresholds'] = ['good_up_to' => $good, 'poor_from' => $poor];
        }
        $breakdown = [];
        foreach ($rows as $value => $row) {
            if (!is_array($row)) {
                continue;
            }
            $formatted = $this->row($metric, $row);
            if (($row['is_all'] ?? false) === true || $value === 'all') {
                $result['total'] = $formatted;
                continue;
            }
            $isRealValue = ($row['is_other'] ?? false) !== true && ($row['is_not_set'] ?? false) !== true;
            if ($isRealValue && $formatted !== null) {
                $breakdown[] = ['value' => (string)$value] + $formatted;
            }
        }
        if ($dimension !== '') {
            $result['by_' . $dimension] = $breakdown;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null Null for a row without percentiles
     */
    private function row(string $metric, array $row): ?array
    {
        $percentiles = is_array($row['percentiles'] ?? null) ? $row['percentiles'] : [];
        if ($percentiles === []) {
            return null;
        }
        $formatted = ['samples' => (int)($row['count'] ?? 0)];
        foreach ($percentiles as $percentile => $value) {
            $rounded = $metric === 'cls' ? round((float)$value, 3) : (int)round((float)$value);
            $formatted['p' . $percentile] = $rounded;
            if (isset(self::THRESHOLDS[$metric])) {
                $formatted['p' . $percentile . '_status'] = $this->status($metric, (float)$value);
            }
        }
        $distribution = is_array($row['distributions'] ?? null) ? $row['distributions'] : [];
        foreach (['good', 'moderate', 'poor'] as $bucket) {
            if (isset($distribution[$bucket])) {
                $formatted[$bucket . '_pct'] = round((float)$distribution[$bucket], 1);
            }
        }

        return $formatted;
    }

    private function status(string $metric, float $value): string
    {
        [$good, $poor] = self::THRESHOLDS[$metric];

        return match (true) {
            $value <= $good => 'good',
            $value < $poor => 'moderate',
            default => 'poor',
        };
    }
}
