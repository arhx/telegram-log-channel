<?php

namespace Arhx\TelegramLogChannel;

use Monolog\Handler\NullHandler;
use Monolog\Logger;

class CreateTelegramLogger
{
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('telegram');

        $token = $config['token'] ?? null;
        $chatId = $config['chat_id'] ?? null;

        if ($token && $chatId) {
            $logger->pushHandler(new TelegramHandler(
                $token,
                $chatId,
                Logger::toMonologLevel($config['level'] ?? 'error')
            ));
        } else {
            $logger->pushHandler(new NullHandler());
        }

        return $logger;
    }
}
