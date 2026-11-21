<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\McpComparisonGateway;
use App\Review\PublicUrl;
use App\Review\ReviewInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class McpTransportTest extends TestCase
{
    public function testOfficialSdkPerformsTheHandshakeAndCallsCompare(): void
    {
        $methods = [];
        $input = new ReviewInput('https://example.com/before', 'https://example.com/after', 'Le bouton Acheter reste visible.');
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$methods, $input) {
            self::assertSame('https://mcp.example.com/mcp', $url);
            $headers = implode("\n", $options['headers']);
            self::assertStringContainsString('x-snapshotsiteapi-key: test-only-key', strtolower($headers));
            if ($method === 'DELETE') {
                return new MockResponse('', ['http_code' => 204]);
            }
            $request = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            $methods[] = $request['method'];
            if (!isset($request['id'])) {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ($request['method'] === 'server/discover') {
                return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32601, 'message' => 'Method not found']], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
            }
            $result = match ($request['method']) {
                'initialize' => ['protocolVersion' => $request['params']['protocolVersion'], 'serverInfo' => ['name' => 'snapshot-site-test', 'version' => '1.0.0'], 'capabilities' => ['tools' => new \stdClass()]],
                'tools/call' => ['content' => [], 'structuredContent' => ['before' => ['link' => 'https://cdn.example.com/before.png'], 'after' => ['link' => 'https://cdn.example.com/after.png'], 'summary' => ['mismatchPercentage' => 4.2]]],
                default => throw new \LogicException('Unexpected MCP method: '.$request['method']),
            };
            if ($request['method'] === 'tools/call') {
                self::assertSame('compare', $request['params']['name']);
                self::assertSame($input->comparisonArguments(), $request['params']['arguments']);
            }

            return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        });
        $gateway = new McpComparisonGateway('test-only-key', 'https://mcp.example.com/mcp', new PublicUrl(static fn () => ['93.184.216.34']), $http);
        $evidence = $gateway->compare($input);
        self::assertSame('https://cdn.example.com/after.png', $evidence->after);
        self::assertSame(4.2, $evidence->mismatch);
        self::assertContains('initialize', $methods);
        self::assertContains('notifications/initialized', $methods);
        self::assertContains('tools/call', $methods);
    }
}
