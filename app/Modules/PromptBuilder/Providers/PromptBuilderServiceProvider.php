<?php

namespace App\Modules\PromptBuilder\Providers;

use App\Modules\PromptBuilder\Console\Commands\BuildPromptCommand;
use Illuminate\Support\ServiceProvider;

final class PromptBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/prompt-builder.php',
            'prompt-builder'
        );
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            BuildPromptCommand::class,
        ]);

        $this->publishes([
            __DIR__ . '/../Config/prompt-builder.php'
                => config_path('prompt-builder.php'),
        ], 'prompt-builder-config');
    }
}
