<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || str_contains(rawurldecode($path), "\0")) {
    http_response_code(400);
    echo 'Requête invalide.';

    return true;
}
$file = realpath(__DIR__.rawurldecode($path));
if ($path !== '/' && $file && str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require __DIR__.'/index.php';
