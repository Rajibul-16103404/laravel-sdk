<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk;

use Illuminate\Support\ServiceProvider;

/**
 * OmnicastServiceProvider
 *
 * Registers the package configuration and binds OmnicastService
 * as a singleton in the Laravel service container.
 */
class OmnicastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Publishes the package config file so it can be customised by the host app.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [
                    __DIR__.'/../config/omnicast.php' => config_path('omnicast.php'),
                ],
                'omnicast-config',
            );
        }
    }

    /**
     * Register any application services.
     *
     * Merges the package config and binds the OmnicastService singleton.
     */
    public function register(): void
    {
        // Merge package defaults with any published config so keys are always present.
        $this->mergeConfigFrom(
            __DIR__.'/../config/omnicast.php',
            'omnicast',
        );

        // Bind as a singleton: one instance per application lifecycle.
        $this->app->singleton(OmnicastService::class, function ($app): OmnicastService {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('omnicast', []);

            return new OmnicastService($config);
        });

        // Register a short-form alias so the facade works out of the box.
        $this->app->alias(OmnicastService::class, 'omnicast');
    }

    /**
     * Declare which services are provided by this provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [OmnicastService::class, 'omnicast'];
    }
}
