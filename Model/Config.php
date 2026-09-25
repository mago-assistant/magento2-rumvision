<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    private const XML_PATH_ENABLED = 'mago_rumvision/general/enabled';
    private const XML_PATH_API_KEY = 'mago_rumvision/general/api_key';
    private const XML_PATH_DOMAIN = 'mago_rumvision/general/domain';
    private const XML_PATH_TIMEOUT = 'mago_rumvision/general/timeout';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED) && $this->getApiKey() !== '';
    }

    public function getApiKey(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_API_KEY);

        return $value !== '' ? trim($this->encryptor->decrypt($value)) : '';
    }

    public function getTimeout(): int
    {
        $timeout = (int)$this->scopeConfig->getValue(self::XML_PATH_TIMEOUT);

        return $timeout > 0 ? $timeout : 20;
    }

    /**
     * The domain chosen in the admin for this website (website scope first, then default), or ''
     */
    public function getConfiguredDomain(?int $websiteId = null): string
    {
        $value = $websiteId !== null
            ? $this->scopeConfig->getValue(self::XML_PATH_DOMAIN, ScopeInterface::SCOPE_WEBSITES, $websiteId)
            : $this->scopeConfig->getValue(self::XML_PATH_DOMAIN);

        return $this->normalize((string)$value);
    }

    /**
     * Every domain saved in the admin, in any scope
     *
     * @return string[]
     */
    public function getConfiguredDomains(): array
    {
        $domains = [$this->getConfiguredDomain()];
        foreach ($this->storeManager->getWebsites() as $website) {
            $domains[] = $this->getConfiguredDomain((int)$website->getId());
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * Admins paste URLs; RUMvision wants the bare hostname.
     */
    public function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://' . $domain, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
