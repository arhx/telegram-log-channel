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
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestCommand::class,
            ]);
        }

        if (env('TELEGRAM_LOG_QUEUE_FAILURES', true) && class_exists(Queue::class)) {
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


