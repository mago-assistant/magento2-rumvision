<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

class GetUxScores extends AbstractAction
{
    public function getName(): string
    {
        return 'get_ux_scores';
    }

    public function getDescription(): string
    {
        return 'UX score and FUX score (one number for the overall real-user experience) per period, optionally per '
            . 'dimension value and filtered by device';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getParameterSchema(): array
    {
        return $this->domainParam() + $this->periodsParam() + $this->dimensionParam() + $this->deviceParam()
            + $this->percentilesParam();
    }

    protected function run(array $params): array
    {
        $body = ['periods' => $this->periods($params), 'filters' => $this->filters($params)];
        if (($percentiles = $this->percentiles($params)) !== null) {
            $body['percentiles'] = $percentiles;
        }
        $path = '/data/ux-scores/' . rawurlencode($this->domain($params));
        $dimension = trim((string)($params['dimension'] ?? ''));
        if ($dimension !== '') {
            $path .= '/' . rawurlencode($dimension);
        }

        return $this->apiClient->post($path, $body);
    }
}
