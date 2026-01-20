<?php

namespace Arhx\TelegramLogChannel;

use Monolog\Logger;

class CreateTelegramLogger
{
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('telegram');
        $logger->pushHandler(new TelegramHandler(
            $config['token'],
            $config['chat_id'],
            Logger::toMonologLevel($config['level'] ?? 'error')
        ));
        return $logger;
    }
}
