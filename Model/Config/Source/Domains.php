<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\DomainResolver;

/**
 * The domains the saved API key can read, so the domain cannot be mistyped
 */
class Domains implements OptionSourceInterface
{
    public function __construct(
        private readonly DomainResolver $domainResolver,
        private readonly Config $config
    ) {
    }

    public function toOptionArray(): array
    {
        $domains = $this->domainResolver->getApiDomains();
        $options = [['value' => '', 'label' => __('Automatic: the domain matching the store URL')]];
        if ($domains === [] && $this->config->getApiKey() === '') {
            $options[0]['label'] = __('Save an API key first');
        }
        // A saved domain must stay selectable when the API list is empty (rate limit, outage): otherwise the
        // browser falls back to the first option and the next save silently clears the domain.
        $saved = array_diff($this->config->getConfiguredDomains(), $domains);
        foreach (array_unique([...$domains, ...$saved]) as $domain) {
            $label = in_array($domain, $domains, true) ? $domain : $domain . ' ' . __('(saved)');
            $options[] = ['value' => $domain, 'label' => $label];
        }

        return $options;
    }
}
