<?php

namespace Arhx\TelegramLogChannel;

use Arhx\TelegramLogChannel\Console\TestCommand;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class TelegramLogChannelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/telegram-log-channel.php' => config_path('telegram-log-channel.php'),
        ], 'telegram-log-channel-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                TestCommand::class,
            ]);
        }

        if (config('telegram-log-channel.queue_failures', true) && class_exists(Queue::class)) {
            Queue::failing(function (JobFailed $event) {
                Log::channel('telegram')->error('Queue job failed: ' . $event->job->getName(), [
                    'exception' => $event->exception->getMessage(),
                    'connection' => $event->connectionName,
                    'queue' => $event->job->getQueue(),
                ]);
            });
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telegram-log-channel.php', 'telegram-log-channel');

        if (!$this->app->make('config')->has('logging.channels.telegram')) {
            $this->app->make('config')->set('logging.channels.telegram', [
                'driver' => 'telegram',
                'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
                'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
                'topic_id' => env('TELEGRAM_LOG_TOPIC_ID'),
                'level' => env('TELEGRAM_LOG_LEVEL', 'error'),
                'throttle' => env('TELEGRAM_LOG_THROTTLE', 600),
            ]);
        }

        Log::extend('telegram', function ($app, array $config) {
            return (new CreateTelegramLogger())($config);
        });
    }
}


