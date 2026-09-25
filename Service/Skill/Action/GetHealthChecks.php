<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

class GetHealthChecks extends AbstractAction
{
    private const STATUSES = [
        'feature_disabled',
        'paused',
        'building_retention',
        'not_enough_data',
        'all_fixed',
        'open',
        'no_issues',
    ];
    private const OPEN_FINDINGS = ['open', 'warning'];

    public function getName(): string
    {
        return 'get_health_checks';
    }

    public function getDescription(): string
    {
        return 'Health checks RUMvision runs for the domain, with their open findings (page template/segment, device, '
            . 'since when) and the remediation steps';
    }

    public function getParameterSchema(): array
    {
        return $this->domainParam() + [
            'status' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => self::STATUSES],
                'description' => 'Only checks with these statuses, e.g. ["open"] for checks with open findings.',
            ],
            'include_closed' => [
                'type' => 'boolean',
                'description' => 'Also return fixed and closed findings (history). Default: only open ones.',
            ],
        ];
    }

    protected function run(array $params): array
    {
        $statuses = array_values(array_intersect((array)($params['status'] ?? []), self::STATUSES));
        $checks = $this->apiClient->get(
            '/health-checks/' . rawurlencode($this->domain($params)),
            $statuses !== [] ? ['status' => $statuses] : []
        )['data'] ?? [];
        $includeClosed = ($params['include_closed'] ?? false) === true;

        $result = [];
        foreach (is_array($checks) ? $checks : [] as $check) {
            if (!is_array($check)) {
                continue;
            }
            $findings = [];
            foreach ((array)($check['todos'] ?? []) as $todo) {
                $isOpen = is_array($todo) && in_array($todo['status'] ?? '', self::OPEN_FINDINGS, true);
                if (!is_array($todo) || (!$includeClosed && !$isOpen)) {
                    continue;
                }
                $findings[] = array_filter([
                    'title' => $todo['title'] ?? null,
                    'status' => $todo['status'] ?? null,
                    'segment' => $todo['filter_value'] ?? null,
                    'device' => $todo['device_type'] ?? null,
                    'opened_at' => $todo['opened_at'] ?? null,
                    'fix_applied_at' => $todo['fix_applied_at'] ?? null,
                    'description' => isset($todo['description']) ? $this->shorten($todo['description']) : null,
                ], static fn ($value): bool => $value !== null && $value !== '');
            }
            $result[] = [
                'slug' => $check['slug'] ?? null,
                'title' => $check['title'] ?? null,
                'status' => $check['status'] ?? null,
                'remediation' => array_values(array_filter(array_map(
                    static fn ($step): string => is_array($step) ? (string)($step['title'] ?? '') : '',
                    (array)($check['remediation_steps'] ?? [])
                ))),
                'findings' => $findings,
            ];
        }

        return ['health_checks' => $result];
    }
}
