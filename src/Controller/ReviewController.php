<?php

declare(strict_types=1);

namespace App\Controller;

use App\Review\DemoReview;
use App\Review\PublicUrl;
use App\Review\ReviewFailure;
use App\Review\ReviewInput;
use App\Review\RunnerFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReviewController extends AbstractController
{
    public function __construct(private readonly RunnerFactory $runners, private readonly DemoReview $demos, private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        return $this->form($request);
    }

    #[Route('/demo/{scenario}', name: 'demo', methods: ['POST'])]
    public function demo(Request $request, string $scenario): Response
    {
        if (!$this->validToken($request)) {
            return $this->form($request, $this->translator->trans('La session a expiré. Rechargez la page et réessayez.'), 403);
        }
        try {
            $report = $this->demos->run($scenario, $request->getLocale());
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        return $this->privateResponse($this->render('report.html.twig', ['report' => $report]));
    }

    #[Route('/app.webmanifest', name: 'manifest', methods: ['GET'])]
    public function manifest(Request $request): Response
    {
        $data = [
            'name' => 'Snapshot Site — Visual Release Review',
            'short_name' => 'Release Review',
            'lang' => $request->getLocale(),
            'start_url' => $this->generateUrl('home', ['lang' => $request->getLocale()]),
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#ff6a00',
            'background_color' => '#f6f7f2',
            'icons' => [
                ['src' => '/images/favicon.png', 'sizes' => '96x96', 'type' => 'image/png'],
                ['src' => '/images/icon-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ];

        return $this->privateResponse(new Response(json_encode($data, JSON_THROW_ON_ERROR), headers: ['Content-Type' => 'application/manifest+json']));
    }

    #[Route('/review', name: 'review', methods: ['POST'])]
    public function review(Request $request): Response
    {
        if (!$this->validToken($request)) {
            return $this->form($request, $this->translator->trans('La session a expiré. Rechargez la page et réessayez.'), 403);
        }
        if (!$this->configured()) {
            return $this->form($request, $this->translator->trans('La comparaison de vos pages nécessite les clés Snapshot Site et du modèle. Les exemples restent disponibles.'), 503);
        }
        $session = $request->getSession();
        $attempts = array_filter($session->get('attempts', []), static fn (int $time) => $time > time() - 60);
        if (count($attempts) >= 3) {
            return $this->form($request, $this->translator->trans('Trois comparaisons ont déjà été lancées. Réessayez dans une minute.'), 429);
        }
        try {
            $urls = new PublicUrl();
            $input = ReviewInput::fromArray($request->request->all(), $urls, $request->getLocale());
        } catch (\InvalidArgumentException $exception) {
            return $this->form($request, $this->translator->trans($exception->getMessage()), 422);
        }
        $session->set('attempts', [...$attempts, time()]);
        $session->save();
        try {
            $report = $this->runners->create()->run($input);

            return $this->privateResponse($this->render('report.html.twig', ['report' => $report]));
        } catch (\Throwable $error) {
            $failure = ReviewFailure::wrap('configuration', $error);
            // Log only fixed categories; provider messages can contain secrets.
            error_log(json_encode(['event' => 'review_failed', 'stage' => $failure->stage, 'reason' => $failure->reason], JSON_THROW_ON_ERROR));

            return $this->form($request, $this->translator->trans($failure->getMessage()), 502);
        }
    }

    private function form(Request $request, ?string $error = null, int $status = 200): Response
    {
        $session = $request->getSession();
        if (!$session->has('token')) {
            $session->set('token', bin2hex(random_bytes(32)));
        }
        $values = [];
        foreach (['before', 'after', 'claim'] as $field) {
            $value = $request->request->all()[$field] ?? '';
            $values[$field] = is_string($value) ? mb_substr($value, 0, 2048) : '';
        }

        return $this->privateResponse($this->render('home.html.twig', ['token' => $session->get('token'), 'configured' => $this->configured(), 'error' => $error, 'values' => $values], new Response(status: $status)));
    }

    private function validToken(Request $request): bool
    {
        $token = $request->request->all()['_token'] ?? null;

        return is_string($token) && hash_equals($request->getSession()->get('token', ''), $token) && $token !== '';
    }

    private function configured(): bool
    {
        return trim($_ENV['SNAPSHOT_SITE_API_KEY'] ?? '') !== '' && trim($_ENV['OPENAI_API_KEY'] ?? '') !== '';
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' https: http:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

        return $response;
    }
}
