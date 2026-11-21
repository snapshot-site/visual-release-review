<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedRequestTest extends WebTestCase
{
    public function testUnknownHostIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', server: ['HTTP_HOST' => 'untrusted.example']);
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('.error-code');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function proxyAddresses(): iterable
    {
        yield 'trusted reverse proxy' => ['192.0.2.10', true];
        yield 'untrusted client' => ['192.0.2.11', false];
    }

    #[DataProvider('proxyAddresses')]
    public function testOnlyTheConfiguredProxyCanDeclareHttps(string $address, bool $secure): void
    {
        $previous = $_ENV['TRUSTED_PROXIES'] ?? null;
        $proxies = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();
        $_ENV['TRUSTED_PROXIES'] = '192.0.2.10';
        try {
            $client = self::createClient();
            $client->request('GET', '/', server: [
                'HTTP_HOST' => 'visual-review.snapshot-site.com',
                'REMOTE_ADDR' => $address,
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'untrusted.example',
            ]);
            self::assertResponseIsSuccessful();
            self::assertSame($secure, $client->getRequest()->isSecure());
            self::assertSame('visual-review.snapshot-site.com', $client->getRequest()->getHost());
            $cookies = $client->getResponse()->headers->getCookies();
            self::assertNotEmpty($cookies);
            foreach ($cookies as $cookie) {
                self::assertTrue($cookie->isHttpOnly());
                self::assertSame('strict', $cookie->getSameSite());
            }
        } finally {
            Request::setTrustedProxies($proxies, $headers & (Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_TRAEFIK));
            if ($previous === null) {
                unset($_ENV['TRUSTED_PROXIES']);
            } else {
                $_ENV['TRUSTED_PROXIES'] = $previous;
            }
        }
    }
}
