<?php

namespace Arhx\TelegramLogChannel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\Handler\AbstractProcessingHandler;
use GuzzleHttp\Client;
use Monolog\LogRecord;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TelegramHandler extends AbstractProcessingHandler
{
    protected Client $client;
    protected string $botToken;
    protected string $chatId;

    public function __construct(string $botToken, string $chatId, int|string|Level $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->client = new Client(['verify' => false]);
        $this->botToken = $botToken;
        $this->chatId = $chatId;
    }

    protected function write(LogRecord $record): void
    {
        if (isset($record['context']['exception'])) {
            $exception = $record['context']['exception'];
            if ($exception instanceof HttpExceptionInterface) {
                $statusCode = $exception->getStatusCode();
                if ($statusCode >= 400 && $statusCode < 500) {
                    return;
                }
            }
        }

        // Get the host or directory name
        $hostOrDirectory = php_sapi_name() === 'cli'
            ? basename(base_path()) // If CLI, get the directory name
            : request()->getHost(); // If not CLI, get the host name
        if($host = gethostname()){
            $hostOrDirectory = "$host:$hostOrDirectory";
        }

        $context = !empty($record['context']) ? json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;
        $extra = !empty($record['extra']) ? json_encode($record['extra'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;
        $params = implode("\n", array_filter([
            $context,
            $extra
        ]));
        if (!empty($params)) {
            $params = "\n" . $params;
        }

        // Format the message with additional data
        $formattedMessage = sprintf(
            "[%s] %s: %s%s",
            $hostOrDirectory,
            $record['level_name'],
            $record['message'],
            $params
        );

        // Send the message
        $this->sendMessage($formattedMessage);
    }

    protected function sendMessage(string $message): void
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $data = [
            'json' => [
                'chat_id' => $this->chatId,
                'text' => Str::limit($message, 4090),
            ],
        ];

        $this->client->post($url, $data);
    }
}
