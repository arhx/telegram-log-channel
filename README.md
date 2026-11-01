# Laravel Telegram Log Channel

A simple Laravel package to send log messages to a Telegram chat.

## Installation

You can install the package via composer:

```bash
composer require arhx/telegram-log-channel
```

The service provider will be automatically registered.

## Configuration

1.  Add the necessary environment variables to your `.env` file:

    ```env
    TELEGRAM_LOG_BOT_TOKEN=your_bot_token_here
    TELEGRAM_LOG_CHAT_ID=your_chat_id_here
    ```

    -   `TELEGRAM_LOG_BOT_TOKEN`: Your Telegram bot's token.
    -   `TELEGRAM_LOG_CHAT_ID`: The ID of the chat where logs should be sent.
    -   `TELEGRAM_LOG_TOPIC_ID`: (Optional) Forum topic (thread) id inside a supergroup — see [Forum topics](#forum-topics-supergroups). Leave empty for a normal group or channel.
    -   `TELEGRAM_LOG_LEVEL`: (Optional) The minimum log level to be sent (defaults to `error`).
    -   `TELEGRAM_LOG_THROTTLE`: (Optional) Number of seconds to suppress duplicate messages (defaults to `600` = 10 minutes). Set to `0` to disable throttling.
    -   `TELEGRAM_LOG_VERIFY_TLS`: (Optional) Verify the TLS certificate of `api.telegram.org` (defaults to `true`). A path to a CA bundle is also accepted. See [TLS verification](#tls-verification).
    -   `TELEGRAM_LOG_INCLUDE_REQUEST`: (Optional) Append the request body to the `Request:` line (defaults to `true`). See [Hiding message content](#hiding-message-content).
    -   `TELEGRAM_LOG_INCLUDE_CONTEXT`: (Optional) Append the JSON of the log context and extra (defaults to `true`).

2.  (Optional) The package comes with a default configuration for the `telegram` log channel. If you need to customize it, you can add your own channel configuration to `config/logging.php`:

    ```php
    'channels' => [
        // ... other channels

        'telegram' => [
            'driver' => 'telegram',
            'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
            'topic_id' => env('TELEGRAM_LOG_TOPIC_ID'), // optional, forum supergroups
            'level' => env('TELEGRAM_LOG_LEVEL', 'debug'), // Example of overriding the level
            'verify' => env('TELEGRAM_LOG_VERIFY_TLS', true), // TLS check of api.telegram.org
            'include_request' => true, // request body in the "Request:" line
            'include_context' => true, // JSON of context + extra
        ],
    ],
    ```

## Forum topics (supergroups)

If the target chat is a **forum supergroup** (topics enabled), Telegram needs a
`message_thread_id` or the message lands in *General*. Set `topic_id` on the
channel (env `TELEGRAM_LOG_TOPIC_ID`) to the topic's id.

**Finding the topic id:** open the topic in Telegram Web / copy a message link —
`https://t.me/c/<chat>/<TOPIC_ID>/<message_id>`. Programmatically, the id is the
`message_thread_id` of any message posted in that topic (visible in
`getUpdates`).

Because the driver is registered under the `telegram` driver name, you can define
**several channels on the same bot**, each pointing at a different topic:

```php
'channels' => [
    'telegram' => [ // errors → "ERROR" topic
        'driver' => 'telegram',
        'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
        'topic_id' => env('TELEGRAM_LOG_TOPIC_ID'),
        'level' => 'warning',
    ],

    'telegram_registration' => [ // registration events → "REGISTRATION" topic
        'driver' => 'telegram',
        'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
        'topic_id' => env('TELEGRAM_LOG_REGISTRATION_TOPIC_ID'),
        'level' => 'info',
        'throttle' => 0,
    ],
],
```

Throttling is per chat **and** topic, so the same text sent to two topics is not
cross-suppressed. Test a specific channel with
`php artisan telegram-log:test --channel=telegram_registration`.

## TLS verification

Since 1.6.0 the HTTP client verifies the TLS certificate of `api.telegram.org`
(earlier versions sent requests with `verify => false`, which allowed the bot
token and log contents to be intercepted). Resolution order:

1. the channel's `verify` key in `config/logging.php`;
2. `verify_tls` in `config/telegram-log-channel.php` (env `TELEGRAM_LOG_VERIFY_TLS`);
3. `true`.

A non-boolean string is passed to Guzzle as a path to a CA bundle. If a server
fails with `cURL error 60: SSL certificate problem`, fix its CA store (or point
`TELEGRAM_LOG_VERIFY_TLS` at a bundle) rather than disabling the check.

## Hiding message content

By default a message contains the log text, the request (`Request: POST /path {body}`),
the user id, the source location and the JSON of the record's context and extra.

**Per channel**, two switches drop parts of it:

| Option | Default | Effect when `false` |
|---|---|---|
| `include_request` | `true` | `Request:` line keeps method and path, without the body |
| `include_context` | `true` | no context / extra JSON |

**Per record**, register a hook that decides on the fly (e.g. for tenants whose
data must never leave the server):

```php
use Arhx\TelegramLogChannel\TelegramHandler;
use Monolog\LogRecord;

// e.g. in AppServiceProvider::boot()
TelegramHandler::redactUsing(function (LogRecord $record): bool {
    return auth()->user()?->company?->is_sensitive ?? false;
});
```

When the hook returns `true`, the message is sent **without content**:

```
[host] ERROR: Illuminate\Database\QueryException [приховано]
Source: app/Http/Controllers/ItemController.php:42
User: 17
```

Only the host, level, exception class (if any), `Source:` and `User:` are kept:
no message text (exception messages may carry SQL values), no request line or
body, no context or extra. The marker is `TelegramHandler::REDACTED_MARKER`.

- The hook is global (static) and applies to every telegram channel; pass `null` to remove it.
- If the hook throws, the record is redacted (fails closed) and the hook error is
  written to the `single` channel.
- Throttling keeps working: it is computed from the original record, which is never sent.

## Usage

To receive Telegram notifications for your logs, add the `telegram` channel to your chosen logging stack in `config/logging.php`.

If the environment variables `TELEGRAM_LOG_BOT_TOKEN` and `TELEGRAM_LOG_CHAT_ID` are not set, the `telegram` channel will gracefully fallback to a `NullHandler`, meaning no logs will be sent to Telegram and the application will not crash.

For example, to add it to the default `stack` channel:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'telegram'], // Add 'telegram' here
    'ignore_exceptions' => false,
],
```

Now, any log message that meets the configured level will be sent to your Telegram chat.

## Spam Throttling (Duplicate Suppression)

To avoid flooding your chat with the same error over and over, the package throttles **identical** messages. After a message is sent, any identical message is suppressed for `TELEGRAM_LOG_THROTTLE` seconds (defaults to **600 = 10 minutes**).

Two messages are considered identical when their **level**, **message text**, and **source file:line** match. Volatile data (request body, query params, timestamps) is intentionally excluded from the comparison so that genuinely repeated errors collapse into a single notification.

```env
TELEGRAM_LOG_THROTTLE=600   # suppress duplicates for 10 minutes
TELEGRAM_LOG_THROTTLE=0     # disable throttling entirely
```

This relies on your application's configured cache store (`Cache` facade). If the cache is unavailable for any reason, the package fails open and the message is still sent.

> **Tip for Laravel 12:** You might just need to update your `.env` file to include `telegram` in the logging stack:
> ```env
> LOG_STACK=daily,telegram
> ```

## Queue Job Failure Logging

The package automatically sends a Telegram notification when a queued job fails. This feature is **enabled by default** and works out of the box with Laravel's queue system (Laravel 10, 11, 12).

To disable it, set the following in your `.env`:

```env
TELEGRAM_LOG_QUEUE_FAILURES=false
```

Each failed job notification includes:
- Job class name
- Exception message
- Queue connection name
- Queue name

> **Note:** The notification respects your configured `TELEGRAM_LOG_LEVEL`. Since failures are logged at the `error` level, make sure your level is set to `error` or lower (e.g., `debug`, `info`, `warning`, `error`).

### Using config:cache

If you use `php artisan config:cache` (recommended in production), you must publish the package config so the env variable is captured at cache time:

```bash
php artisan vendor:publish --tag=telegram-log-channel-config
```

This creates `config/telegram-log-channel.php` in your application. The `TELEGRAM_LOG_QUEUE_FAILURES` env variable will then be read correctly even when the config cache is active.

## Testing

### In a Laravel Application
After installing the package in your Laravel app, you can test your configuration by running:
```bash
php artisan telegram-log:test
```
> **Note:** This command sends a message with the `error` level. If your `TELEGRAM_LOG_LEVEL` is set to a higher level (e.g., `emergency`), the test message will not be sent.

### During Package Development
If you are developing the package, you can run the command directly from the root:
1. Copy `.env.example` (if available) to `.env` or create it manually with your bot token and chat ID.
2. Run:
```bash
php artisan telegram-log:test
```
