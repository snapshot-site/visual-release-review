<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\StdioComparisonGateway;
use App\Review\PublicUrl;
use App\Review\ReviewFailure;
use App\Review\ReviewInput;
use PHPUnit\Framework\TestCase;

final class StdioComparisonGatewayTest extends TestCase
{
    public function testStdioUsesTheOfficialSdkWithoutLeakingSecretsIntoArgumentsOrEnvironment(): void
    {
        $gateway = new StdioComparisonGateway('unit-only-key', new PublicUrl(static fn () => ['93.184.216.34']), PHP_BINARY, dirname(__DIR__).'/Fixtures/mcp-stdio-server.php');
        $input = new ReviewInput('https://example.com/before', 'https://example.com/after', 'Le bouton Acheter reste visible.');
        $evidence = $gateway->compare($input);
        self::assertSame('https://cdn.example.com/before.png', $evidence->before);
        self::assertSame('https://cdn.example.com/after.png', $evidence->after);
        self::assertSame(0.0, $evidence->mismatch);
    }

    public function testMissingLocalServerProducesAnActionableError(): void
    {
        $gateway = new StdioComparisonGateway('unit-only-key', new PublicUrl(), PHP_BINARY, __DIR__.'/missing-server.php');
        $this->expectException(ReviewFailure::class);
        $this->expectExceptionMessage('npm ci');
        $gateway->compare(new ReviewInput('https://example.com/before', 'https://example.com/after', 'Le bouton Acheter reste visible.'));
    }
}
