<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

class ListAnnotations extends AbstractAction
{
    public function getName(): string
    {
        return 'list_annotations';
    }

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function getDescription(): string
    {
        return 'Most recent annotations (deploys and other changes with their impact date, category and affected '
            . 'templates), to relate changes in the numbers to what was released';
    }

    public function getParameterSchema(): array
    {
        return $this->fromParam() + $this->limitParam();
    }

    protected function run(array $params): array
    {
        // The API returns every annotation the key can see (all domains, no paging), without the domains they apply to.
        $annotations = array_values(array_filter(
            (array)($this->apiClient->get('/annotations')['data'] ?? []),
            'is_array'
        ));
        $from = trim((string)($params['from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $annotations = array_filter(
                $annotations,
                static fn (array $annotation): bool => substr((string)($annotation['impact_at'] ?? ''), 0, 10) >= $from
            );
        }
        usort($annotations, static fn (array $a, array $b): int => strcmp(
            (string)($b['impact_at'] ?? ''),
            (string)($a['impact_at'] ?? '')
        ));
        $limit = max(1, min((int)($params['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));

        return [
            'total' => count($annotations),
            'annotations' => array_map([$this, 'summarize'], array_slice($annotations, 0, $limit)),
        ];
    }

    /**
     * @param array<string, mixed> $annotation
     * @return array<string, mixed>
     */
    private function summarize(array $annotation): array
    {
        $category = $annotation['category'] ?? null;

        return array_filter([
            'title' => $annotation['title'] ?? null,
            'impact_at' => $annotation['impact_at'] ?? null,
            'category' => is_array($category) ? ($category['name'] ?? null) : null,
            'url_scope' => $annotation['url_scope'] ?? null,
            'templates' => array_values(array_filter(array_map(
                static fn ($urlCategory): string => is_array($urlCategory) ? (string)($urlCategory['name'] ?? '') : '',
                (array)($annotation['url_categories'] ?? [])
            ))),
            'description' => isset($annotation['description'])
                ? $this->shorten($annotation['description'], 150)
                : null,
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
