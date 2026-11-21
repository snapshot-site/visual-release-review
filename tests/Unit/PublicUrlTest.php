<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Review\PublicUrl;
use App\Review\ReviewInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicUrlTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function testRejectsLocalAndUnsafeUrls(string $url): void
    {
        $policy = new PublicUrl(static fn () => ['127.0.0.1']);
        $this->expectException(\InvalidArgumentException::class);
        $policy->validate($url);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        foreach (['http://127.0.0.1', 'http://10.0.0.1', 'http://169.254.169.254', 'http://[::1]', 'http://[::ffff:127.0.0.1]', 'http://localhost', 'http://internal.example.com', 'https://user:password@example.com', 'ftp://example.com', 'file:///etc/passwd', 'https://example.com:3000', 'https://example.com/#private', 'javascript:alert(1)', 'http://2130706433'] as $url) {
            yield $url => [$url];
        }
    }

    public function testAcceptsAPublicPreviewUrl(): void
    {
        $policy = new PublicUrl(static fn () => ['93.184.216.34']);
        self::assertSame('https://preview.example.com/product?version=2', $policy->validate('https://preview.example.com/product?version=2'));
    }

    public function testRejectsMixedPublicAndPrivateDnsAnswers(): void
    {
        $policy = new PublicUrl(static fn () => ['93.184.216.34', '10.0.0.2']);
        $this->expectException(\InvalidArgumentException::class);
        $policy->validate('https://example.com');
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidInputs')]
    public function testInvalidFormValuesAreRejected(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReviewInput::fromArray($data, new PublicUrl(static fn () => ['93.184.216.34']));
    }

    /** @return iterable<int, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield [['before' => [], 'after' => 'https://example.com', 'claim' => 'Le bouton est visible.']];
        yield [['before' => 'https://example.com', 'after' => 'https://example.com', 'claim' => 'court']];
        yield [['before' => 'https://example.com', 'after' => 'https://example.com', 'claim' => str_repeat('é', 301)]];
        yield [[]];
    }
}
