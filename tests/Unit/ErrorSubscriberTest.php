<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\ErrorSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final class ErrorSubscriberTest extends TestCase
{
    public function testTemplateFailureStillProducesASafeErrorResponse(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willThrowException(new \RuntimeException('private-template-path'));
        $locales = new LocaleSwitcher('fr', []);
        $handler = new ErrorSubscriber($twig, $locales);
        $request = Request::create('/?lang=en');
        $request->setLocale('en');
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('private-api-key'));
        $handler->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Something went wrong.', (string) $response->getContent());
        self::assertStringContainsString('href="/?lang=en"', (string) $response->getContent());
        self::assertStringNotContainsString('private-', (string) $response->getContent());
        self::assertSame('fr', $locales->getLocale());
    }
}
