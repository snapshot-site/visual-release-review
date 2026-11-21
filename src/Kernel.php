<?php

declare(strict_types=1);

namespace App;

use App\Controller\ReviewController;
use App\EventSubscriber\ErrorSubscriber;
use App\EventSubscriber\LocaleSubscriber;
use App\Infrastructure\LiveRunnerFactory;
use App\Review\DemoReview;
use App\Review\RunnerFactory;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'local-visual-review',
            'test' => $this->environment === 'test',
            'http_method_override' => false,
            'default_locale' => 'fr',
            'enabled_locales' => ['fr', 'en'],
            'translator' => ['default_path' => '%kernel.project_dir%/translations', 'fallbacks' => ['fr']],
            'session' => ['storage_factory_id' => $this->environment === 'test' ? 'session.storage.factory.mock_file' : 'session.storage.factory.native', 'cookie_httponly' => true, 'cookie_samesite' => 'strict', 'cookie_secure' => 'auto'],
            'router' => ['utf8' => true],
        ]);
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true]);
        $container->services()->set(ReviewController::class)->autowire()->autoconfigure()->tag('controller.service_arguments')->public();
        $container->services()->set(LocaleSubscriber::class)->autoconfigure();
        $container->services()->set(ErrorSubscriber::class)->autowire()->autoconfigure();
        $container->services()->set(DemoReview::class)->autowire();
        $container->services()->set(LiveRunnerFactory::class);
        $container->services()->alias(RunnerFactory::class, LiveRunnerFactory::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/Controller/', 'attribute');
    }
}
