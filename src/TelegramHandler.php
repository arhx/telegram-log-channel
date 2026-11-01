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
    /** Placeholder sent instead of the log message text in redacted mode. */
    public const REDACTED_MARKER = '[приховано]';

    protected Client $client;
    protected string $botToken;
    protected string $chatId;
    protected int $throttleSeconds;

    /**
     * Forum topic (thread) inside a supergroup, i.e. Telegram's
     * `message_thread_id`. Null = post to the group's General thread.
     */
    protected ?int $topicId;

    /** Options the Guzzle client was built with (kept for introspection). */
    protected array $clientOptions;

    /** Append the request body (input minus _method/_token) to the Request line. */
    protected bool $includeRequest;

    /** Append the JSON-encoded record context and extra. */
    protected bool $includeContext;

    /**
     * Global hook deciding per record whether to send a content-free message.
     * Signature: fn (LogRecord $record): bool — true means redact.
     *
     * @var (callable(LogRecord): bool)|null
     */
    protected static $redactResolver = null;

    private static bool $isHandling = false;

    /**
     * @param bool|string $verify Guzzle `verify` option: true (default) checks the
     *                            TLS certificate of api.telegram.org, a string is a
     *                            path to a CA bundle, false disables the check.
     */
    public function __construct(
        string $botToken,
        string $chatId,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        int $throttleSeconds = 600,
        int|string|null $topicId = null,
        bool|string $verify = true,
        bool $includeRequest = true,
        bool $includeContext = true,
    ) {
        parent::__construct($level, $bubble);
        $this->clientOptions = [
            'verify' => $verify,
            'timeout' => 5,
            'connect_timeout' => 3,
        ];
        $this->client = new Client($this->clientOptions);
        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->throttleSeconds = $throttleSeconds;
        $this->topicId = ($topicId === null || $topicId === '') ? null : (int) $topicId;
        $this->includeRequest = $includeRequest;
        $this->includeContext = $includeContext;
    }

    /**
     * Register a callback that decides, per log record, whether the message must
     * be sent without content (no message text, request, context or extra).
     * Pass null to remove the hook.
     *
     * @param (callable(LogRecord): bool)|null $resolver
     */
    public static function redactUsing(?callable $resolver): void
    {
        static::$redactResolver = $resolver;
    }

    public function getClientOptions(): array
    {
        return $this->clientOptions;
    }

    protected function write(LogRecord $record): void
    {
        if (self::$isHandling) {
            return;
        }

        self::$isHandling = true;

        try {
            $exception = $record['context']['exception'] ?? null;
            if ($exception instanceof HttpExceptionInterface) {
                $statusCode = $exception->getStatusCode();
                if ($statusCode >= 400 && $statusCode < 500) {
                    return;
                }
            }

            // Get the host or directory name
            $isCli = $this->runningInConsole();
            $hostOrDirectory = $isCli
                ? basename(base_path()) // If CLI, get the directory name
                : request()->getHost(); // If not CLI, get the host name
            if($host = gethostname()){
                $hostOrDirectory = $host === $hostOrDirectory ? $host : "$host:$hostOrDirectory";
            }

            // Extract first non-vendor file:line from exception trace
            $sourceLocation = '';
            if ($exception instanceof \Throwable) {
                $sourceLocation = $this->extractSourceLocation($exception);
            }

            // Throttle identical messages: skip if the same error was already sent
            // within the throttle window. The signature ignores volatile data
            // (request body, timestamps) so genuinely identical errors collapse.
            if ($this->isThrottled($record, $sourceLocation)) {
                return;
            }

            $redact = $this->shouldRedact($record);

            // Collect metadata lines
            $meta = [];

            if ($sourceLocation) {
                $meta[] = "Source: $sourceLocation";
            }

            // Request info (only in HTTP context, never in redacted mode)
            if (!$isCli && !$redact) {
                try {
                    $request = request();
                    $method = $request->method();
                    $path = $request->getPathInfo();
                    $requestJson = '';
                    if ($this->includeRequest) {
                        $requestData = $request->except(['_method', '_token']);
                        $requestJson = !empty($requestData) ? ' ' . json_encode($requestData, JSON_UNESCAPED_UNICODE) : '';
                    }
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

            $params = '';
            if ($this->includeContext && !$redact) {
                $context = !empty($record['context']) ? json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;
                $extra = !empty($record['extra']) ? json_encode($record['extra'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;
                $params = implode("\n", array_filter([$context, $extra]));
                if ($params !== '') {
                    $params = "\n" . $params;
                }
            }

            if ($redact) {
                // Only the exception class survives: its message may carry data
                // (e.g. SQL exceptions include bound values).
                $body = $exception instanceof \Throwable
                    ? get_class($exception) . ' ' . self::REDACTED_MARKER
                    : self::REDACTED_MARKER;
            } else {
                $body = $record['message'];
            }

            // Format the message with additional data
            $formattedMessage = sprintf(
                "[%s] %s: %s%s%s",
                $hostOrDirectory,
                $record['level_name'],
                $body,
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
     * Ask the registered hook whether this record must be redacted. Fails
     * closed: if the hook throws, the record is redacted and the hook's error
     * is reported to the fallback channel.
     */
    protected function shouldRedact(LogRecord $record): bool
    {
        if (static::$redactResolver === null) {
            return false;
        }

        try {
            return (bool) (static::$redactResolver)($record);
        } catch (\Throwable $e) {
            $this->logSelfError($e);

            return true;
        }
    }

    protected function runningInConsole(): bool
    {
        return php_sapi_name() === 'cli';
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
            $signature = md5($record['level_name'] . '|' . $record['message'] . '|' . $sourceLocation . '|' . $this->chatId . '|' . $this->topicId);
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
        $payload = [
            'chat_id' => $this->chatId,
            'text' => Str::limit($message, 4090),
        ];

        // Forum supergroups: without message_thread_id Telegram drops the message
        // into General. Only sent when a topic is configured, so plain groups and
        // channels keep working unchanged.
        if ($this->topicId !== null) {
            $payload['message_thread_id'] = $this->topicId;
        }

        $this->client->post($url, ['json' => $payload]);
    }
}
