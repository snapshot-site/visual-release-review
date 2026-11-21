<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $kernel = new Kernel('prod', false);
    $request = Request::create('http://localhost/app.webmanifest');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Application health check failed.');
    }
    echo 'ok';
} catch (Throwable) {
    http_response_code(503);
    echo 'unavailable';
}
