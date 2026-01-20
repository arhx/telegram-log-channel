<?php

namespace Arhx\TelegramLogChannel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class TelegramLogChannelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telegram-log.php' => config_path('telegram-log.php'),
            ], 'config');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telegram-log.php', 'telegram-log');

        Log::extend('telegram', function ($app, array $config) {
            return (new CreateTelegramLogger())($config);
        });
    }
}
