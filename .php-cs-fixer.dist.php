<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/public', __DIR__.'/translations'])
    ->append([__DIR__.'/config/bundles.php', __DIR__.'/bin/console', __FILE__])
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@Symfony' => true,
        'yoda_style' => false,
    ])
    ->setCacheFile(__DIR__.'/var/php-cs-fixer.cache')
    ->setFinder($finder);
