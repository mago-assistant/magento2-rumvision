<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

class ListDomains extends AbstractAction
{
    public function getName(): string
    {
        return 'list_domains';
    }

    public function getDescription(): string
    {
        return 'List the RUMvision domains the API key can read, with their default percentile, and which store views '
            . 'each domain measures';
    }

    public function getParameterSchema(): array
    {
        return [];
    }

    protected function run(array $params): array
    {
        return [
            'store_views_by_domain' => $this->domainResolver->getDomains(),
            'domains' => $this->apiClient->get('/domains')['data'] ?? [],
        ];
    }
}
