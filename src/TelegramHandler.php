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

    private static bool $isHandling = false;
    
    protected function write(LogRecord $record): void
    {
        if (self::$isHandling) {
            return;
        }

        self::$isHandling = true;

        try {
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
                $hostOrDirectory = $host === $hostOrDirectory ? $host : "$host:$hostOrDirectory";
            }

            // Extract first non-vendor file:line from exception trace
            $sourceLocation = '';
            if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
                $sourceLocation = $this->extractSourceLocation($record['context']['exception']);
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
                "%s[%s] %s: %s%s",
                $sourceLocation,
                $hostOrDirectory,
                $record['level_name'],
                $record['message'],
                $params
            );

            // Send the message
            $this->sendMessage($formattedMessage);
        } catch (\Throwable $e) {
            $this->logSelfError($e);
        } finally {
            self::$isHandling = false;
        }
    }

    protected function extractSourceLocation(\Throwable $exception): string
    {
        $basePath = base_path() . DIRECTORY_SEPARATOR;
        $vendorPath = $basePath . 'vendor' . DIRECTORY_SEPARATOR;

        // Check the exception's own file first, then walk the trace
        $candidates = [['file' => $exception->getFile(), 'line' => $exception->getLine()]];
        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $candidates[] = $frame;
            }
        }

        foreach ($candidates as $frame) {
            $file = $frame['file'];
            if (!str_starts_with($file, $vendorPath) && str_starts_with($file, $basePath)) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($basePath)));
                return "$relative:{$frame['line']}\n";
            }
        }

        return '';
    }

    protected function logSelfError(\Throwable $e): void
    {
        try {
            Log::channel('single')->error("Telegram Logger Error: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        } catch (\Throwable $fallbackException) {
            error_log("Telegram Logger Error: " . $e->getMessage());
        }
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
