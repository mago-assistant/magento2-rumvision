<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

class GetAlerts extends AbstractAction
{
    public function getName(): string
    {
        return 'get_alerts';
    }

    public function getDescription(): string
    {
        return 'Days on which one of the domain\'s alerts fired, newest first, with the segments that breached and '
            . 'RUMvision\'s AI analysis when one was made';
    }

    public function getParameterSchema(): array
    {
        return $this->domainParam() + $this->fromParam() + [
            'to' => ['type' => 'string', 'description' => 'Latest day, YYYY-MM-DD. Default: today.'],
        ] + $this->limitParam();
    }

    protected function run(array $params): array
    {
        $query = ['limit' => max(1, min((int)($params['limit'] ?? 20), 100))];
        foreach (['from', 'to'] as $key) {
            $value = trim((string)($params[$key] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $query[$key] = $value;
            }
        }

        return $this->apiClient->get('/alerts/' . rawurlencode($this->domain($params)), $query);
    }
}
