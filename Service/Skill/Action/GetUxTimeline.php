<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

use MagoAssistant\Rumvision\Service\RumvisionException;

class GetUxTimeline extends AbstractAction
{
    private const INTERVALS = [1, 2, 4, 8, 12, 30];

    public function getName(): string
    {
        return 'get_ux_timeline';
    }

    public function getDescription(): string
    {
        return 'UX and FUX scores over time within a period, split into intervals, to see when the experience changed';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getParameterSchema(): array
    {
        return $this->domainParam() + $this->periodsParam() + [
            'interval' => [
                'type' => 'string',
                'description' => 'Bucket size: a number (1, 2, 4, 8, 12 or 30) plus a unit h (hours), d (days), w '
                    . '(weeks) '
                    . 'or M (months), e.g. "1d" or "1w". Default "1d".',
            ],
        ] + $this->deviceParam() + $this->percentilesParam();
    }

    protected function run(array $params): array
    {
        $body = [
            'periods' => $this->periods($params),
            'interval' => $this->interval((string)($params['interval'] ?? '1d')),
            'filters' => $this->filters($params),
        ];
        if (($percentiles = $this->percentiles($params)) !== null) {
            $body['percentiles'] = $percentiles;
        }

        return $this->apiClient->post('/data/ux-scores-timeline/' . rawurlencode($this->domain($params)), $body);
    }

    /**
     * @return array{interval: int, unit: string}
     * @throws RumvisionException
     */
    private function interval(string $value): array
    {
        $isValid = preg_match('/^\s*(\d+)\s*(m|h|d|w|M)\s*$/', $value, $match)
            && in_array((int)$match[1], self::INTERVALS, true);
        if (!$isValid) {
            throw new RumvisionException('Interval must be 1, 2, 4, 8, 12 or 30 followed by m, h, d, w or M, e.g. '
                . '"1d".');
        }

        return ['interval' => (int)$match[1], 'unit' => $match[2]];
    }
}
