<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainResolver;
use MagoAssistant\Rumvision\Service\Skill\MetricsFormatter;

class GetMetrics extends AbstractAction
{
    public function __construct(
        ApiClient $apiClient,
        Config $config,
        DomainResolver $domainResolver,
        private readonly MetricsFormatter $formatter
    ) {
        parent::__construct($apiClient, $config, $domainResolver);
    }

    public function getName(): string
    {
        return 'get_metrics';
    }

    public function getDescription(): string
    {
        return 'Core Web Vitals and other metrics (percentiles and good/moderate/poor distribution with sample counts) '
            . 'per period, optionally broken down by one dimension and filtered by device';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getParameterSchema(): array
    {
        return $this->domainParam() + $this->metricsParam() + $this->periodsParam() + $this->dimensionParam()
            + $this->deviceParam() + $this->percentilesParam();
    }

    protected function run(array $params): array
    {
        $body = [
            'metrics' => array_values(array_filter(
                array_map('strval', (array)($params['metrics'] ?? ['lcp', 'inp', 'cls']))
            )),
            'periods' => $this->periods($params),
            'filters' => $this->filters($params),
        ];
        if (($percentiles = $this->percentiles($params)) !== null) {
            $body['percentiles'] = $percentiles;
        }
        $path = '/data/distribution/' . rawurlencode($this->domain($params));
        $dimension = trim((string)($params['dimension'] ?? ''));
        if ($dimension !== '') {
            $path .= '/' . rawurlencode($dimension);
        }

        return $this->formatter->format($this->apiClient->post($path, $body), $dimension);
    }
}
