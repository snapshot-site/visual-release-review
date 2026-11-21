<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $session = $request->getSession();
        $choice = $request->query->all()['lang'] ?? $session->get('locale', 'fr');
        $locale = in_array($choice, ['fr', 'en'], true) ? $choice : 'fr';
        $request->setLocale($locale);
        $session->set('locale', $locale);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set('Content-Language', $event->getRequest()->getLocale());
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 64], KernelEvents::RESPONSE => 'onResponse'];
    }
}
