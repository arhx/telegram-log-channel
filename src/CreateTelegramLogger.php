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
                Logger::toMonologLevel($config['level'] ?? 'error'),
                true,
                (int) ($config['throttle'] ?? 600),
                $config['topic_id'] ?? null,
                $this->verifyOption($config['verify'] ?? config('telegram-log-channel.verify_tls', true)),
                $this->toBool($config['include_request'] ?? true, true),
                $this->toBool($config['include_context'] ?? true, true),
            ));
        } else {
            $logger->pushHandler(new NullHandler());
        }

        return $logger;
    }

    /**
     * Guzzle `verify`: boolean-like values become bools, any other non-empty
     * string is treated as a path to a CA bundle. Empty/null keeps the check on.
     */
    protected function verifyOption(mixed $value): bool|string
    {
        if ($value === null || $value === '') {
            return true;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool !== null) {
            return $bool;
        }

        return (string) $value;
    }

    protected function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
