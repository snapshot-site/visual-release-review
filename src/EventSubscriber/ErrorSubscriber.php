<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final readonly class ErrorSubscriber implements EventSubscriberInterface
{
    public function __construct(private Environment $twig, private LocaleSwitcher $locales)
    {
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || $event->isKernelTerminating()) {
            return;
        }
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];
        $locale = $event->getRequest()->getLocale() === 'en' ? 'en' : 'fr';
        [$title, $message] = match ($status) {
            400 => ['Requête invalide.', 'La demande n’a pas pu être comprise. Revenez au formulaire et vérifiez les informations saisies.'],
            401 => ['Connexion nécessaire.', 'Connectez-vous pour accéder à cette page.'],
            403 => ['Accès refusé.', 'Vous n’avez pas accès à cette page. Vous pouvez revenir à l’accueil.'],
            404 => ['Cette page est introuvable.', 'Le lien a peut-être changé ou la page n’existe plus. Retrouvez le formulaire depuis l’accueil.'],
            405 => ['Repartons du formulaire.', 'Cette adresse attend l’envoi du formulaire. Ouvrez l’accueil pour lancer une comparaison.'],
            408, 504 => ['La demande a pris trop de temps.', 'Le délai de réponse est dépassé. Vous pouvez réessayer depuis l’accueil dans un instant.'],
            410 => ['Cette page n’est plus disponible.', 'Ce contenu a été retiré. Retrouvez le formulaire depuis l’accueil.'],
            413 => ['La demande est trop volumineuse.', 'Réduisez la taille des informations envoyées, puis réessayez depuis le formulaire.'],
            415 => ['Ce format n’est pas accepté.', 'Utilisez le formulaire de comparaison pour envoyer votre demande.'],
            422 => ['Quelques informations sont à vérifier.', 'Vérifiez les deux URL et le point à examiner, puis renvoyez le formulaire.'],
            429 => ['Un peu de patience.', 'Trop de demandes ont été envoyées. Attendez un instant avant de réessayer.'],
            502, 503 => ['Le service est momentanément indisponible.', 'Nous ne pouvons pas traiter votre demande pour le moment. Réessayez dans un instant.'],
            default => $status >= 500
                ? ['Un problème est survenu.', 'Nous n’avons pas pu afficher cette page. Réessayez dans un instant depuis l’accueil.']
                : ['La demande ne peut pas aboutir.', 'Revenez au formulaire pour vérifier votre demande et réessayer.'],
        };
        try {
            $content = $this->locales->runWithLocale($locale, fn (): string => $this->twig->render('error.html.twig', ['status' => $status, 'error_title' => $title, 'error_message' => $message]));
        } catch (\Throwable) {
            // Keep a safe response even when the template engine is unavailable.
            $heading = $locale === 'en' ? 'Something went wrong.' : 'Un problème est survenu.';
            $link = $locale === 'en' ? 'Back to home' : 'Retour à l’accueil';
            $content = '<!doctype html><html lang="'.$locale.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>HTTP '.$status.'</title></head><body><main><p>HTTP '.$status.'</p><h1>'.$heading.'</h1><a href="/?lang='.$locale.'">'.$link.'</a></main></body></html>';
        }
        if ($status >= 500) {
            // Never log provider messages, request data or stack traces here.
            error_log(json_encode(['event' => 'http_error', 'status' => $status], JSON_THROW_ON_ERROR));
        }
        $response = new Response($content, $status, $headers);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' https: http:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        $event->setResponse($response);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }
}
