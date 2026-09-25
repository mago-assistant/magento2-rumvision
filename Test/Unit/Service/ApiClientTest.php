<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Service;

use MagoAssistant\Rumvision\Model\Config;
use MagoAssistant\Rumvision\Service\ApiClient;
use MagoAssistant\Rumvision\Service\RumvisionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApiClientTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: string}> */
    private array $requests = [];

    #[Test]
    public function sendsTheApiKeyAsBearerTokenAndReturnsTheJson(): void
    {
        $client = $this->client(new MockResponse('{"data":[{"domain":"shop.nl"}]}'));

        $result = $client->get('/domains', ['includeMetrics' => 'true']);

        self::assertSame(['data' => [['domain' => 'shop.nl']]], $result);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('https://insights.rumvision.com/api/v1/domains?includeMetrics=true', $this->requests[0]['url']);
        self::assertContains('Authorization: Bearer key-1', $this->requests[0]['headers']);
    }

    #[Test]
    public function postsJson(): void
    {
        $client = $this->client(new MockResponse('{}'));

        $client->post('/data/distribution/shop.nl', ['metrics' => ['lcp'], 'filters' => new \stdClass()]);

        self::assertSame('{"metrics":["lcp"],"filters":{}}', $this->requests[0]['body']);
    }

    #[Test]
    public function refusesToCallWithoutAnApiKey(): void
    {
        $this->expectException(RumvisionException::class);
        $this->expectExceptionMessage('not configured');

        $this->client(new MockResponse('{}'), '')->get('/domains');
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function errors(): array
    {
        return [
            'bad key' => [401, '{"message":"Unauthenticated."}', 'rejected the API key'],
            'unknown domain or role' => [404, '{"message":"Not found"}', 'Use list_domains'],
            'validation' => [422, '{"message":"Invalid.","errors":{"periods.current.from":["The from field is required."]}}', 'periods.current.from: The from field is required.'],
            'rate limit' => [429, '{"message":"Too many requests, retry in 30s"}', 'retry in 30s'],
            'rate limit with retry-after' => [429, '{}', 'Try again in 42 seconds.'],
        ];
    }

    #[Test]
    #[DataProvider('errors')]
    public function explainsApiErrors(int $status, string $body, string $expected): void
    {
        $this->expectException(RumvisionException::class);
        $this->expectExceptionMessage($expected);

        $headers = $status === 429 && $body === '{}' ? ['retry-after: 42'] : [];
        $this->client(new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]))->get('/domains');
    }

    private function client(MockResponse $response, string $apiKey = 'key-1'): ApiClient
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn($apiKey);
        $config->method('getTimeout')->willReturn(5);

        return new ApiClient($config, new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => (string)($options['body'] ?? '')];
            return $response;
        }));
    }
}
