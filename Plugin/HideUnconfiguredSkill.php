<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Plugin;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Rumvision\Model\Config;

/**
 * Without an API key every call would fail, so the skill is not offered to the assistant at all.
 * It stays in getAllTools() so its grants can be set on the Skills page beforehand.
 */
class HideUnconfiguredSkill
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @param ToolRegistry $subject
     * @param ToolInterface[] $result
     * @return ToolInterface[]
     */
    public function afterGetEnabledTools(ToolRegistry $subject, array $result): array
    {
        if (!$this->config->isConfigured()) {
            unset($result['rumvision']);
        }
        return $result;
    }
}
