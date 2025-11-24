<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Providers;

use Illuminate\Support\ServiceProvider;

class VeriFactuServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Registrar bindings, singletons, etc.
        $this->mergeConfigFrom(__DIR__ . '/../../config/verifactu.php', 'verifactu');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publicar archivos de configuración
            $this->publishes([
                __DIR__ . '/../../config/verifactu.php' => config_path('verifactu.php'),
            ], 'verifactu-config');

            // Publicar migraciones solo si está habilitado en config
            if (config('verifactu.load_migrations', false)) {
                $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
            }

            // Registrar comandos
            $this->commands([
                \Squareetlabs\VeriFactu\Console\Commands\MakeAdapterCommand::class,
            ]);
        }
    }
}