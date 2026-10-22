<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Model\Config\Source;

use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Model\Config\Source\Domains;
use MagoAssistant\Rumvision\Service\DomainResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DomainsTest extends TestCase
{
    #[Test]
    public function listsTheKeysDomainsAfterAutomatic(): void
    {
        $options = $this->source(['shop.nl', 'other.nl'], ['shop.nl'])->toOptionArray();

        self::assertSame(['', 'shop.nl', 'other.nl'], array_column($options, 'value'));
    }

    #[Test]
    public function keepsASavedDomainSelectableWhenTheApiListIsEmpty(): void
    {
        $options = $this->source([], ['shop.nl'])->toOptionArray();

        self::assertSame(['', 'shop.nl'], array_column($options, 'value'));
        self::assertSame('shop.nl (saved)', (string)$options[1]['label']);
    }

    /**
     * @param string[] $apiDomains
     * @param string[] $saved
     */
    private function source(array $apiDomains, array $saved): Domains
    {
        $resolver = $this->createStub(DomainResolver::class);
        $resolver->method('getApiDomains')->willReturn($apiDomains);
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn('key');
        $config->method('getConfiguredDomains')->willReturn($saved);

        return new Domains($resolver, $config);
    }
}
