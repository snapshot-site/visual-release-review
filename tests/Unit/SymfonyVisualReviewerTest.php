<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\SymfonyVisualReviewer;
use App\Review\Evidence;
use App\Review\ReviewInput;
use App\Review\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SymfonyVisualReviewerTest extends TestCase
{
    public function testEmptyModelNameIsRejectedBeforeCallingTheProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SymfonyVisualReviewer::create('sk-test-not-a-real-key', '   ');
    }

    /** @return iterable<string, array{string, string}> */
    public static function languages(): iterable
    {
        yield 'French' => ['fr', 'French'];
        yield 'English' => ['en', 'English'];
    }

    #[DataProvider('languages')]
    public function testRealSymfonyAgentsSendBothImagesAndCriticSeesTheInspection(string $locale, string $language): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/responses', $url);
            $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $body;
            self::assertSame(['type' => 'json_object'], $body['text']['format']);
            self::assertSame(800, $body['max_output_tokens']);
            self::assertFalse($body['store']);
            self::assertStringContainsString('JSON', json_encode($body['input'], JSON_THROW_ON_ERROR));
            $finding = json_encode(['verdict' => 'contradicted', 'observation' => 'Le bandeau masque le bouton.', 'evidence' => 'after', 'limitation' => 'Le clic n’est pas testé.'], JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode(['id' => 'resp_test', 'model' => 'gpt-4o-mini', 'status' => 'completed', 'output' => [['type' => 'message', 'id' => 'msg_test', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $finding, 'annotations' => []]]]]], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        });
        $platform = Factory::createPlatform('sk-test-not-a-real-key', $http);
        $reviewer = new SymfonyVisualReviewer(new Agent($platform, 'gpt-4o-mini', name: 'inspector'), new Agent($platform, 'gpt-4o-mini', name: 'critic'));
        $input = new ReviewInput('https://example.com/before', 'https://example.com/after', 'Le bouton Acheter reste visible.', $locale);
        $evidence = new Evidence('https://cdn.example.com/before.png', 'https://cdn.example.com/after.png');
        $inspection = $reviewer->review($input, $evidence);
        $critique = $reviewer->review($input, $evidence, $inspection);
        self::assertSame(Verdict::Contradicted, $critique->verdict);
        self::assertCount(2, $requests);
        foreach ($requests as $body) {
            $serialized = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            self::assertStringContainsString($evidence->before, $serialized);
            self::assertStringContainsString($evidence->after, $serialized);
            self::assertStringContainsString('input_image', $serialized);
            self::assertStringContainsString('Answer in natural, concise '.$language.'.', $serialized);
        }
        self::assertStringNotContainsString('"inspector":', json_encode($requests[0], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('inspector', json_encode($requests[1], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('critic', json_encode($requests[1], JSON_THROW_ON_ERROR));
    }
}
