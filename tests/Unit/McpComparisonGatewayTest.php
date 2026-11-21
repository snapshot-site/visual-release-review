<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\McpComparisonGateway;
use App\Review\PublicUrl;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;

final class McpComparisonGatewayTest extends TestCase
{
    public function testDecodesStructuredSnapshotSiteResponse(): void
    {
        $evidence = McpComparisonGateway::decode(new CallToolResult([], structuredContent: $this->payload()), $this->urls());
        self::assertSame('https://cdn.example.com/before.png', $evidence->before);
        self::assertSame('https://cdn.example.com/diff.png', $evidence->diff);
        self::assertSame(4.25, $evidence->mismatch);
    }

    public function testDecodesTextOnlyMcpResponse(): void
    {
        $evidence = McpComparisonGateway::decode(CallToolResult::success([new TextContent(json_encode($this->payload(), JSON_THROW_ON_ERROR))]), $this->urls());
        self::assertSame('https://cdn.example.com/after.png', $evidence->after);
    }

    public function testDoesNotExposeAnUpstreamErrorAsEvidence(): void
    {
        $this->expectException(\RuntimeException::class);
        McpComparisonGateway::decode(CallToolResult::error([new TextContent('secret-key upstream detail')]), $this->urls());
    }

    public function testRejectsMissingCaptures(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        McpComparisonGateway::decode(new CallToolResult([], structuredContent: ['diff' => ['link' => 'https://cdn.example.com/diff.png']]), $this->urls());
    }

    public function testRejectsAnUnsafeImageUrl(): void
    {
        $payload = $this->payload();
        $payload['after']['link'] = 'javascript:alert(1)';
        $this->expectException(\InvalidArgumentException::class);
        McpComparisonGateway::decode(new CallToolResult([], structuredContent: $payload), $this->urls());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['before' => ['link' => 'https://cdn.example.com/before.png'], 'after' => ['link' => 'https://cdn.example.com/after.png'], 'diff' => ['link' => 'https://cdn.example.com/diff.png'], 'summary' => ['mismatchPercentage' => 4.25]];
    }

    private function urls(): PublicUrl
    {
        return new PublicUrl(static fn () => ['93.184.216.34']);
    }
}
