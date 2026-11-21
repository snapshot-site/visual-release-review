<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Review\ComparisonGateway;
use App\Review\Evidence;
use App\Review\Finding;
use App\Review\ReviewFailure;
use App\Review\ReviewRunner;
use App\Review\RunnerFactory;
use App\Review\Verdict;
use App\Review\VisualReviewer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReviewControllerTest extends WebTestCase
{
    public function testHomeExplainsTheReplayAndDoesNotCacheTokens(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Un petit changement.');
        self::assertSelectorTextContains('.demo-panel', 'Exemples préenregistrés');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
    }

    public function testLanguageChoicePersistsThroughReportsAndCanBeChanged(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?lang=en');
        self::assertResponseHeaderSame('Content-Language', 'en');
        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', 'One small change.');
        self::assertSelectorTextContains('.section-label', 'YOUR TWO VERSIONS');
        $client->submitForm('See the regression');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Language', 'en');
        self::assertSelectorTextContains('[data-testid="verdict"]', 'Contradicted');
        self::assertSelectorTextContains('.agent-card', 'the fixed banner covers');
        self::assertSelectorTextContains('.verdict-panel', 'Both reviews agree');
        $client->request('GET', '/');
        self::assertSelectorExists('html[lang="en"]');
        $client->click($client->getCrawler()->filter('a[hreflang="fr"]')->link());
        self::assertResponseHeaderSame('Content-Language', 'fr');
        self::assertSelectorTextContains('h1', 'Un petit changement.');
    }

    public function testEnglishErrorsPreserveTheLanguageAndSubmittedValues(): void
    {
        $client = self::createClient();
        $client->request('POST', '/demo/broken?lang=en', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('[role="alert"]', 'Your session has expired');
        $crawler = $client->request('GET', '/');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/review', ['_token' => $token, 'before' => 'https://example.com/before', 'after' => 'https://example.com/after', 'claim' => 'The Buy button remains visible.']);
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', 'requires Snapshot Site and model API keys');
        self::assertSelectorExists('input[name="before"][value="https://example.com/before"]');
    }

    public function testManifestUsesTheSelectedLanguage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/app.webmanifest?lang=en');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/manifest+json');
        self::assertResponseHeaderSame('Content-Language', 'en');
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('en', $manifest['lang']);
        self::assertSame('/?lang=en', $manifest['start_url']);
    }

    public function testUnsupportedOrArrayLanguageFallsBackToFrench(): void
    {
        $client = self::createClient();
        foreach (['/?lang=de', '/?lang[]=en'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Language', 'fr');
            self::assertSelectorTextContains('h1', 'Un petit changement.');
        }
    }

    public function testCrossSitePostIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/demo/broken', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('[role="alert"]', 'La session a expiré');
    }

    public function testDemoRendersTheEvidenceAndBothReviewers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        $client->submitForm('Voir la régression');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="verdict"]', 'Contredit');
        self::assertSelectorCount(2, '.evidence-grid img');
        self::assertSelectorCount(2, '.agent-card');
        self::assertSelectorTextContains('.replay-notice', 'sans appel aux agents');
    }

    public function testMissingKeysDoNotSilentlySwitchToDemo(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/review', ['_token' => $token, 'before' => 'https://example.com', 'after' => 'https://example.com/after', 'claim' => 'Le bouton Acheter reste visible.']);
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', 'nécessite les clés');
        self::assertSelectorNotExists('[data-testid="verdict"]');
    }

    public function testInvalidInputIsRejectedBeforeAnyProviderCall(): void
    {
        $_ENV['OPENAI_API_KEY'] = 'test-only';
        $_ENV['SNAPSHOT_SITE_API_KEY'] = 'test-only';
        try {
            $client = self::createClient();
            $crawler = $client->request('GET', '/');
            $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
            $client->request('POST', '/review', ['_token' => $token, 'before' => 'http://127.0.0.1', 'after' => 'http://127.0.0.1', 'claim' => 'Le bouton Acheter reste visible.']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role="alert"]', 'adresses locales');
        } finally {
            $_ENV['OPENAI_API_KEY'] = '';
            $_ENV['SNAPSHOT_SITE_API_KEY'] = '';
        }
    }

    public function testArrayTokenIsRejectedWithoutAServerError(): void
    {
        $client = self::createClient();
        $client->request('POST', '/review', ['_token' => ['bad']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLiveRouteUsesTheRunnerAndEscapesSubmittedText(): void
    {
        $_ENV['OPENAI_API_KEY'] = 'test-only';
        $_ENV['SNAPSHOT_SITE_API_KEY'] = 'test-only';
        try {
            $client = self::createClient();
            $client->disableReboot();
            $gateway = $this->createMock(ComparisonGateway::class);
            $gateway->expects(self::once())->method('compare')->with(self::callback(static fn ($input) => $input->locale === 'en'))->willReturn(new Evidence('/fixtures/before.png', '/fixtures/broken.png'));
            $reviewer = $this->createMock(VisualReviewer::class);
            $reviewer->expects(self::exactly(2))->method('review')->willReturnOnConsecutiveCalls(
                new Finding(Verdict::Confirmed, 'Le bouton semble visible.', 'after', 'Le clic n’est pas testé.'),
                new Finding(Verdict::Contradicted, 'Le bandeau masque le bouton.', 'after', 'Le clic n’est pas testé.'),
            );
            $factory = $this->createMock(RunnerFactory::class);
            $factory->expects(self::once())->method('create')->willReturn(new ReviewRunner($gateway, $reviewer));
            self::getContainer()->set(RunnerFactory::class, $factory);
            $crawler = $client->request('GET', '/?lang=en');
            $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
            $client->request('POST', '/review', ['_token' => $token, 'before' => 'https://1.1.1.1/before', 'after' => 'https://1.1.1.1/after', 'claim' => '<script>alert("xss")</script> reste visible.']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-testid="verdict"]', 'Inconclusive');
            self::assertSelectorTextContains('.replay-notice', 'Live comparison');
            $content = $client->getResponse()->getContent();
            self::assertIsString($content);
            self::assertStringNotContainsString('<script>alert(', $content);
            self::assertStringContainsString('&lt;script&gt;', $content);
        } finally {
            $_ENV['OPENAI_API_KEY'] = '';
            $_ENV['SNAPSHOT_SITE_API_KEY'] = '';
        }
    }

    public function testInvalidSnapshotSiteKeyProducesASpecificSafeError(): void
    {
        $_ENV['OPENAI_API_KEY'] = 'test-only';
        $_ENV['SNAPSHOT_SITE_API_KEY'] = 'test-only';
        try {
            $client = self::createClient();
            $client->disableReboot();
            $gateway = $this->createMock(ComparisonGateway::class);
            $gateway->method('compare')->willThrowException(ReviewFailure::fromToolMessage('Invalid API credentials private-key-value'));
            $reviewer = $this->createMock(VisualReviewer::class);
            $reviewer->expects(self::never())->method('review');
            $factory = $this->createMock(RunnerFactory::class);
            $factory->method('create')->willReturn(new ReviewRunner($gateway, $reviewer));
            self::getContainer()->set(RunnerFactory::class, $factory);
            $crawler = $client->request('GET', '/');
            $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
            $client->request('POST', '/review', ['_token' => $token, 'before' => 'https://1.1.1.1/before', 'after' => 'https://1.1.1.1/after', 'claim' => 'Le bouton Acheter reste visible.']);
            self::assertResponseStatusCodeSame(502);
            self::assertSelectorTextContains('[role="alert"]', 'Snapshot Site a refusé la clé API');
            $content = $client->getResponse()->getContent();
            self::assertIsString($content);
            self::assertStringNotContainsString('private-key-value', $content);
            self::assertSelectorNotExists('[data-testid="verdict"]');
        } finally {
            $_ENV['OPENAI_API_KEY'] = '';
            $_ENV['SNAPSHOT_SITE_API_KEY'] = '';
        }
    }

    public function testUpstreamFailureStaysAnErrorAndRepeatedCallsAreThrottled(): void
    {
        $_ENV['OPENAI_API_KEY'] = 'test-only';
        $_ENV['SNAPSHOT_SITE_API_KEY'] = 'test-only';
        try {
            $client = self::createClient();
            $client->disableReboot();
            $gateway = $this->createMock(ComparisonGateway::class);
            $gateway->expects(self::exactly(3))->method('compare')->willThrowException(new \RuntimeException('secret-provider-detail'));
            $reviewer = $this->createMock(VisualReviewer::class);
            $reviewer->expects(self::never())->method('review');
            $factory = $this->createMock(RunnerFactory::class);
            $factory->expects(self::exactly(3))->method('create')->willReturn(new ReviewRunner($gateway, $reviewer));
            self::getContainer()->set(RunnerFactory::class, $factory);
            $crawler = $client->request('GET', '/');
            $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
            $data = ['_token' => $token, 'before' => 'https://1.1.1.1/before', 'after' => 'https://1.1.1.1/after', 'claim' => 'Le bouton Acheter reste visible.'];
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $client->request('POST', '/review', $data);
                self::assertResponseStatusCodeSame(502);
                self::assertSelectorNotExists('[data-testid="verdict"]');
                $content = $client->getResponse()->getContent();
                self::assertIsString($content);
                self::assertStringNotContainsString('secret-provider-detail', $content);
            }
            $client->request('POST', '/review', $data);
            self::assertResponseStatusCodeSame(429);
        } finally {
            $_ENV['OPENAI_API_KEY'] = '';
            $_ENV['SNAPSHOT_SITE_API_KEY'] = '';
        }
    }
}
