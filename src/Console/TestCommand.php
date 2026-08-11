<?php

namespace Arhx\TelegramLogChannel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestCommand extends Command
{
    protected $signature = 'telegram-log:test {--channel=telegram : Logging channel to test (any channel using the telegram driver)}';
    protected $description = 'Sends a test message to the Telegram channel';

    public function handle()
    {
        $channel = $this->option('channel');

        $this->info("Checking Telegram channel configuration [{$channel}]...");

        $config = config("logging.channels.{$channel}");

        if (empty($config)) {
            $this->error("Configuration for logging.channels.{$channel} not found.");
            $this->line('Please make sure the channel is configured in your config/logging.php file.');
            return 1;
        }

        $token = $config['token'] ?? null;
        $chatId = $config['chat_id'] ?? null;

        if (empty($token) || empty($chatId)) {
            $this->error('TELEGRAM_LOG_BOT_TOKEN and/or TELEGRAM_LOG_CHAT_ID are not configured.');
            $this->line('Please check your .env file or the channel configuration in config/logging.php.');
            return 1;
        }

        $topicId = $config['topic_id'] ?? null;
        $this->info("Configuration found (chat {$chatId}" . ($topicId ? ", topic {$topicId}" : '') . '). Attempting to send a test message...');

        try {
            Log::channel($channel)->error("Hello from your Telegram Log Channel! This is a test message sent via the telegram-log:test command (channel: {$channel}).");
            $this->info('Test message dispatched successfully! Please check your Telegram chat.');
        } catch (\Exception $e) {
            $this->error('An error occurred while sending the message:');
            $this->error($e->getMessage());
            return 1;
        }

        return 0;
    }
}
