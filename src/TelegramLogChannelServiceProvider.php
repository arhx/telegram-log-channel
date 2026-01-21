<?php

namespace Arhx\TelegramLogChannel;

use Arhx\TelegramLogChannel\Console\TestCommand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class TelegramLogChannelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        if (!$this->app->make('config')->has('logging.channels.telegram')) {
            $this->app->make('config')->set('logging.channels.telegram', [
                'driver' => 'telegram',
                'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
                'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
                'level' => env('TELEGRAM_LOG_LEVEL', 'error'),
            ]);
        }

        Log::extend('telegram', function ($app, array $config) {
            return (new CreateTelegramLogger())($config);
        });
    }
}


