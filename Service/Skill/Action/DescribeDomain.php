<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service\Skill\Action;

use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\DomainCatalog;
use MagoAssistant\Rumvision\Service\DomainResolver;

class DescribeDomain extends AbstractAction
{
    public function __construct(
        ApiClient $apiClient,
        Config $config,
        DomainResolver $domainResolver,
        private readonly DomainCatalog $domainCatalog
    ) {
        parent::__construct($apiClient, $config, $domainResolver);
    }

    public function getName(): string
    {
        return 'describe_domain';
    }

    public function getDescription(): string
    {
        return 'The metric slugs, dimension slugs (for "dimension", e.g. hostname, template-type, domain-path) and '
            . 'URL categories (templates) of a domain, and its default percentile';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getParameterSchema(): array
    {
        return $this->domainParam();
    }

    protected function run(array $params): array
    {
        return $this->domainCatalog->describe($this->domain($params));
    }
}
