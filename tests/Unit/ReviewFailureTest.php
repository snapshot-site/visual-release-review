<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Review\ReviewFailure;
use Mcp\Client\Exception\AuthorizationException;
use Mcp\Client\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;

final class ReviewFailureTest extends TestCase
{
    #[DataProvider('toolErrors')]
    public function testProviderDetailsBecomeSafeMessages(string $detail, string $reason): void
    {
        $failure = ReviewFailure::fromToolMessage($detail.' ss-secret-key');
        self::assertSame($reason, $failure->reason);
        self::assertStringNotContainsString('ss-secret-key', $failure->getMessage());
    }

    /** @return iterable<int, array{string, string}> */
    public static function toolErrors(): iterable
    {
        yield ['Invalid API credentials', 'credentials'];
        yield ['Unauthorized (401)', 'credentials'];
        yield ['Quota exceeded for API key', 'quota'];
        yield ['Too many requests (429)', 'quota'];
        yield ['Request timed out', 'timeout'];
        yield ['Unknown upstream private details', 'provider'];
    }

    public function testWrappedMcpAuthenticationFailureIsRecognized(): void
    {
        $error = new ConnectionException('private-details', 0, new AuthorizationException('401 private-token'));
        $failure = ReviewFailure::wrap('capture', $error);
        self::assertSame('mcp_authentication', $failure->reason);
        self::assertStringNotContainsString('private-token', $failure->getMessage());
        self::assertSame($error, $failure->getPrevious());
    }

    public function testModelAuthenticationFailureNamesTheCorrectService(): void
    {
        $failure = ReviewFailure::wrap('critic', new AuthenticationException('private-token'));
        self::assertSame('credentials', $failure->reason);
        self::assertStringContainsString('OpenAI', $failure->getMessage());
        self::assertStringNotContainsString('private-token', $failure->getMessage());
    }

    public function testModelQuotaFailureIsNotReportedAsInvalidCredentials(): void
    {
        $failure = ReviewFailure::wrap('inspector', new RateLimitExceededException());
        self::assertSame('quota', $failure->reason);
        self::assertStringContainsString('OpenAI', $failure->getMessage());
    }
}
