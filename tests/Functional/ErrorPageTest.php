<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class ErrorPageTest extends WebTestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function routingErrors(): iterable
    {
        yield '404 FR' => ['/page-absente?lang=fr', 404, 'Cette page joue à cache-cache.'];
        yield '404 EN' => ['/page-absente?lang=en', 404, 'This page is playing hide-and-seek.'];
        yield '405 FR' => ['/review?lang=fr', 405, 'Bonne porte, mauvaise poignée.'];
        yield '405 EN' => ['/review?lang=en', 405, 'Right door, wrong handle.'];
    }

    #[DataProvider('routingErrors')]
    public function testRoutingErrorsShowASafeTranslatedPage(string $url, int $status, string $heading): void
    {
        $client = self::createClient();
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame($status);
        self::assertSelectorTextSame('h1', $heading);
        self::assertSelectorTextSame('.error-code', (string) $status);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        if ($status === 405) {
            self::assertResponseHeaderSame('Allow', 'POST');
        }
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('Stack Trace', $content);
        self::assertStringNotContainsString('vendor/', $content);
        $client->click($client->getCrawler()->filter('.error-page .button')->link());
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function applicationErrors(): iterable
    {
        foreach ([400, 401, 403, 408, 410, 413, 415, 422, 429, 500, 502, 503, 504, 418, 507] as $status) {
            foreach (['fr', 'en'] as $locale) {
                yield $status.' '.$locale => [$status, $locale, true];
            }
        }
        yield '500 production' => [500, 'fr', false];
        yield '405 production' => [405, 'en', false];
    }

    #[DataProvider('applicationErrors')]
    public function testApplicationExceptionsPreserveStatusAndHeadersWithoutLeakingDetails(int $status, string $locale, bool $debug): void
    {
        $client = self::createClient(['debug' => $debug]);
        $client->disableReboot();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function () use ($status): void {
            if ($status === 500) {
                throw new \RuntimeException('secret-provider-key /private/path <script>alert(1)</script>');
            }
            throw new HttpException($status, 'secret-provider-key /private/path', null, ['Retry-After' => '60']);
        });
        $client->request('GET', '/?lang='.$locale);
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Language', $locale);
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHasHeader('Content-Security-Policy');
        self::assertSelectorExists('html[lang="'.$locale.'"]');
        self::assertSelectorTextSame('.error-code', (string) $status);
        self::assertSelectorTextSame('.error-page .button', $locale === 'fr' ? 'Retour à l’accueil' : 'Back to home');
        if ($status !== 500) {
            self::assertResponseHeaderSame('Retry-After', '60');
        } else {
            self::assertSelectorTextSame('h1', $locale === 'fr' ? 'Le serveur a renversé son café.' : 'The server spilled its coffee.');
        }
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        foreach (['secret-provider-key', '/private/path', 'RuntimeException', '<script>alert', 'Stack Trace', 'vendor/'] as $detail) {
            self::assertStringNotContainsString($detail, $content);
        }
    }

    public function testMissingRouteKeepsTheSessionLanguage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?lang=en');
        $client->request('GET', '/not-here');
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Language', 'en');
        self::assertSelectorTextSame('h1', 'This page is playing hide-and-seek.');
    }

    public function testUnknownExampleUsesTheErrorPageAfterAValidSubmission(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/?lang=en');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/demo/unknown', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', 'This page is playing hide-and-seek.');
    }
}
