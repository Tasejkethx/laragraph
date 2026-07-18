<?php

declare(strict_types=1);

namespace Laragraph;

use Illuminate\Support\ServiceProvider;
use Laragraph\Console\BuildGraphCommand;
use Laragraph\Console\CalleesCommand;
use Laragraph\Console\CallersCommand;
use Laragraph\Console\ImpactCommand;

final class LaragraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laragraph.php', 'laragraph');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            BuildGraphCommand::class,
            CallersCommand::class,
            CalleesCommand::class,
            ImpactCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/laragraph.php' => config_path('laragraph.php'),
        ], 'laragraph-config');
    }
}
