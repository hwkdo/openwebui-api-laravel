<?php

namespace Hwkdo\OpenwebuiApiLaravel;

use Hwkdo\OpenwebuiApiLaravel\Commands\OpenwebuiApiLaravelCommand;
use Hwkdo\OpenwebuiApiLaravel\Services\OpenWebUiCompletionService;
use Hwkdo\OpenwebuiApiLaravel\Services\OpenWebUiRagService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OpenwebuiApiLaravelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('openwebui-api-laravel')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_openwebui_api_laravel_table')
            ->hasCommand(OpenwebuiApiLaravelCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(OpenWebUiRagService::class, function ($app) {
            return new OpenWebUiRagService();
        });

        $this->app->singleton(OpenWebUiCompletionService::class, function ($app) {
            return new OpenWebUiCompletionService();
        });
    }
}
