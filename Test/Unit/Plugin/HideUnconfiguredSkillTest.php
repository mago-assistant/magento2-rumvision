<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Plugin;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Plugin\HideUnconfiguredSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HideUnconfiguredSkillTest extends TestCase
{
    #[Test]
    public function offersTheSkillOnlyWhenConfigured(): void
    {
        $tool = $this->createStub(ToolInterface::class);
        $tools = ['rumvision' => $tool, 'sales_data' => $tool];

        self::assertSame(['sales_data'], array_keys($this->plugin(false)->afterGetEnabledTools(new ToolRegistry(), $tools)));
        self::assertSame(['rumvision', 'sales_data'], array_keys($this->plugin(true)->afterGetEnabledTools(new ToolRegistry(), $tools)));
    }

    private function plugin(bool $configured): HideUnconfiguredSkill
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn($configured);

        return new HideUnconfiguredSkill($config);
    }
}
