<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Service;

use MagoAssistant\Rumvision\Model\Config;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * RUMvision Client API v1 (https://insights.rumvision.com/api/v1), authenticated with the project's API key
 */
class ApiClient
{
    private const BASE_URL = 'https://insights.rumvision.com/api/v1';

    /** @var HttpClientInterface Symfony's default client unless one is passed in */
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly Config $config,
        ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @param array<string, mixed> $query
     * @param int|null $timeout Seconds; defaults to the configured timeout
     * @return array<string, mixed>
     * @throws RumvisionException
     */
    public function get(string $path, array $query = [], ?int $timeout = null): array
    {
        return $this->request('GET', $path, ['query' => $query], $timeout);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws RumvisionException
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws RumvisionException
     */
    private function request(string $method, string $path, array $options, ?int $timeout = null): array
    {
        $timeout ??= $this->config->getTimeout();
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new RumvisionException('RUMvision is not configured: enter an API key under Stores > Configuration > '
                . 'Mago Assistant > RUMvision.');
        }

        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, $options + [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'timeout' => $timeout,
                'max_duration' => $timeout,
            ]);
            $status = $response->getStatusCode();
            $retryAfter = (string)($response->getHeaders(false)['retry-after'][0] ?? '');
            $decoded = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            throw new RumvisionException('RUMvision is unreachable: ' . $e->getMessage(), 0, $e);
        }

        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }

        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
        throw new RumvisionException(match (true) {
            $status === 401 => 'RUMvision rejected the API key. Check the key under Stores > Configuration > Mago '
                . 'Assistant > RUMvision.',
            // The API answers 404 both for an unknown domain and for an endpoint the key's role does not cover.
            $status === 404 => 'Domain not found, or the API key has no access to it or to this data (the key needs '
                . 'the "All" role). Use list_domains for the exact domain names.',
            $status === 422 => 'RUMvision rejected the request: '
                . ($message !== '' ? $message : 'invalid parameters') . $this->validationErrors($decoded),
            $status === 429 => 'RUMvision rate limit reached' . ($message !== '' ? ': ' . $message : '')
                . (ctype_digit($retryAfter)
                    ? sprintf('. Try again in %d seconds.', $retryAfter)
                    : '. Try again later.'),
            default => sprintf('RUMvision answered HTTP %d%s.', $status, $message !== '' ? ': ' . $message : ''),
        });
    }

    private function validationErrors(mixed $decoded): string
    {
        if (!is_array($decoded) || !is_array($decoded['errors'] ?? null)) {
            return '';
        }
        $errors = [];
        foreach ($decoded['errors'] as $field => $messages) {
            $errors[] = $field . ': ' . implode(' ', array_map('strval', (array)$messages));
        }

        return $errors !== [] ? ' (' . implode('; ', $errors) . ')' : '';
    }
}
