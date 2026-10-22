<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Integration;

use Magento\Config\Model\Config\TypePool;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Rumvision\Model\Config as RumvisionConfig;
use MagoAssistant\Rumvision\Service\Skill\Action\GetAlerts;
use MagoAssistant\Rumvision\Service\Skill\Rumvision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SkillWiringTest extends TestCase
{
    private const ENABLED_PATH = 'mago_rumvision/general/enabled';
    private const API_KEY_PATH = 'mago_rumvision/general/api_key';

    /** @var ObjectManagerInterface */
    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    protected function tearDown(): void
    {
        $scopeConfig = $this->objectManager->get(MutableScopeConfigInterface::class);
        $scopeConfig->setValue(self::ENABLED_PATH, '0');
        $scopeConfig->setValue(self::API_KEY_PATH, null);
    }

    #[Test]
    public function registersTheSkillWithMago(): void
    {
        $tool = $this->registry()->getToolByName('rumvision');

        self::assertInstanceOf(Rumvision::class, $tool);
    }

    #[Test]
    public function hidesTheSkillFromTheAssistantWhileSwitchedOff(): void
    {
        $this->givenApiKey('key');

        $tools = $this->registry()->getEnabledTools();

        self::assertArrayNotHasKey('rumvision', $tools);
    }

    #[Test]
    public function offersTheSkillOnceEnabledWithAnApiKey(): void
    {
        $this->givenApiKey('key');
        $this->objectManager->get(MutableScopeConfigInterface::class)->setValue(self::ENABLED_PATH, '1');

        $tools = $this->registry()->getEnabledTools();

        self::assertArrayHasKey('rumvision', $tools);
    }

    #[Test]
    public function refusesActionsOnAFreshInstall(): void
    {
        $result = $this->objectManager->create(GetAlerts::class)->execute(['domain' => 'shop.nl'], 1);

        self::assertStringStartsWith('RUMvision is switched off', $result['error'] ?? '');
    }

    #[Test]
    #[Config('mago_rumvision/general/domain', 'shop.nl')]
    #[Config('mago_rumvision/general/domain', 'shop.be', ScopeInterface::SCOPE_WEBSITE, 'base')]
    public function readsTheWebsiteDomainBeforeTheDefaultOne(): void
    {
        $websiteId = (int)$this->objectManager->get(StoreManagerInterface::class)->getWebsite('base')->getId();
        $config = $this->objectManager->get(RumvisionConfig::class);

        $websiteDomain = $config->getConfiguredDomain($websiteId);
        $defaultDomain = $config->getConfiguredDomain();

        self::assertSame('shop.be', $websiteDomain);
        self::assertSame('shop.nl', $defaultDomain);
    }

    #[Test]
    public function keepsTheApiKeyOutOfConfigDumps(): void
    {
        $isSensitive = $this->objectManager->get(TypePool::class)
            ->isPresent(self::API_KEY_PATH, TypePool::TYPE_SENSITIVE);

        self::assertTrue($isSensitive);
    }

    private function givenApiKey(string $apiKey): void
    {
        $encrypted = $this->objectManager->get(EncryptorInterface::class)->encrypt($apiKey);
        $this->objectManager->get(MutableScopeConfigInterface::class)->setValue(self::API_KEY_PATH, $encrypted);
    }

    /**
     * Without Mago's per-admin permission check, so only this module's own filtering decides.
     */
    private function registry(): ToolRegistry
    {
        return $this->objectManager->create(ToolRegistry::class, ['permissionChecker' => null]);
    }
}
