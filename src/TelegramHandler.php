<?php

namespace Arhx\TelegramLogChannel;

use Illuminate\Support\Facades\Cache;
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
    protected int $throttleSeconds;

    public function __construct(string $botToken, string $chatId, int|string|Level $level = Level::Error, bool $bubble = true, int $throttleSeconds = 600)
    {
        parent::__construct($level, $bubble);
        $this->client = new Client([
            'verify' => false,
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);
        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->throttleSeconds = $throttleSeconds;
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
            $isCli = php_sapi_name() === 'cli';
            $hostOrDirectory = $isCli
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

            // Throttle identical messages: skip if the same error was already sent
            // within the throttle window. The signature ignores volatile data
            // (request body, timestamps) so genuinely identical errors collapse.
            if ($this->isThrottled($record, $sourceLocation)) {
                return;
            }

            // Collect metadata lines
            $meta = [];

            if ($sourceLocation) {
                $meta[] = "Source: $sourceLocation";
            }

            // Request info (only in HTTP context)
            if (!$isCli) {
                try {
                    $request = request();
                    $method = $request->method();
                    $path = $request->getPathInfo();
                    $requestData = $request->except(['_method', '_token']);
                    $requestJson = !empty($requestData) ? ' ' . json_encode($requestData, JSON_UNESCAPED_UNICODE) : '';
                    $meta[] = "Request: $method $path$requestJson";
                } catch (\Throwable $e) {
                    // request not available
                }
            }

            // Auth user ID
            try {
                $userId = \Illuminate\Support\Facades\Auth::id();
                if ($userId !== null) {
                    $meta[] = "User: $userId";
                }
            } catch (\Throwable $e) {
                // auth not available
            }

            $metaBlock = !empty($meta) ? "\n" . implode("\n", $meta) : '';

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
                "[%s] %s: %s%s%s",
                $hostOrDirectory,
                $record['level_name'],
                $record['message'],
                $metaBlock,
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

    /**
     * Determine whether an identical message was already sent within the
     * throttle window. Returns true when the message should be suppressed.
     *
     * Uses Cache::add() which is atomic: it only writes (and returns true)
     * when the key is absent, so the first occurrence passes and subsequent
     * identical ones are dropped until the TTL expires. Fails open: if the
     * cache is unavailable for any reason, the message is still sent.
     */
    protected function isThrottled(LogRecord $record, string $sourceLocation): bool
    {
        if ($this->throttleSeconds <= 0) {
            return false;
        }

        try {
            $signature = md5($record['level_name'] . '|' . $record['message'] . '|' . $sourceLocation);
            $key = 'telegram-log:' . $signature;

            // Cache::add returns true if the key was set (i.e. not seen recently).
            return !Cache::add($key, true, $this->throttleSeconds);
        } catch (\Throwable $e) {
            return false;
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
                return "$relative:{$frame['line']}";
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
