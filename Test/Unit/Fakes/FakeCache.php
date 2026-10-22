<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Fakes;

use Magento\Framework\App\CacheInterface;

/**
 * In-memory cache that keeps what was saved, with the lifetime it was saved for
 */
class FakeCache implements CacheInterface
{
    /** @var array<string, string> */
    private array $data = [];

    /** @var array<string, int|null> */
    private array $lifetimes = [];

    public function getFrontend()
    {
        throw new \LogicException('FakeCache has no frontend');
    }

    public function load($identifier)
    {
        return $this->data[$identifier] ?? '';
    }

    /**
     * @param string[] $tags
     */
    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->data[$identifier] = (string)$data;
        $this->lifetimes[$identifier] = $lifeTime;

        return true;
    }

    public function remove($identifier)
    {
        unset($this->data[$identifier], $this->lifetimes[$identifier]);

        return true;
    }

    /**
     * @param string[] $tags
     */
    public function clean($tags = [])
    {
        $this->data = [];
        $this->lifetimes = [];

        return true;
    }

    public function lifetimeOf(string $identifier): ?int
    {
        return $this->lifetimes[$identifier] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }
}
