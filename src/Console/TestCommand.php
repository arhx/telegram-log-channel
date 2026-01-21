<?php

namespace Arhx\TelegramLogChannel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestCommand extends Command
{
    protected $signature = 'telegram-log:test';
    protected $description = 'Sends a test message to the Telegram channel';

    public function handle()
    {
        $this->info('Checking Telegram channel configuration...');

        $config = config('logging.channels.telegram');

        if (empty($config)) {
            $this->error('Configuration for logging.channels.telegram not found.');
            $this->line('Please make sure the telegram channel is configured in your config/logging.php file.');
            return 1;
        }

        $token = $config['token'] ?? null;
        $chatId = $config['chat_id'] ?? null;

        if (empty($token) || empty($chatId)) {
            $this->error('TELEGRAM_LOG_BOT_TOKEN and/or TELEGRAM_LOG_CHAT_ID are not configured.');
            $this->line('Please check your .env file or the channel configuration in config/logging.php.');
            return 1;
        }

        $this->info("Configuration found. Attempting to send a test message...");

        try {
            Log::channel('telegram')->error('Hello from your Telegram Log Channel! This is a test message sent via the telegram-log:test command.');
            $this->info('Test message dispatched successfully! Please check your Telegram chat.');
        } catch (\Exception $e) {
            $this->error('An error occurred while sending the message:');
            $this->error($e->getMessage());
            return 1;
        }

        return 0;
    }
}
