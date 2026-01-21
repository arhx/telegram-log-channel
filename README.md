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
    -   `TELEGRAM_LOG_LEVEL`: (Optional) The minimum log level to be sent (defaults to `error`).

2.  (Optional) The package comes with a default configuration for the `telegram` log channel. If you need to customize it, you can add your own channel configuration to `config/logging.php`:

    ```php
    'channels' => [
        // ... other channels

        'telegram' => [
            'driver' => 'telegram',
            'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
            'level' => env('TELEGRAM_LOG_LEVEL', 'debug'), // Example of overriding the level
        ],
    ],
    ```

## Usage

To receive Telegram notifications for your logs, add the `telegram` channel to your chosen logging stack in `config/logging.php`.

For example, to add it to the default `stack` channel:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'telegram'], // Add 'telegram' here
    'ignore_exceptions' => false,
],
```

Now, any log message that meets the configured level will be sent to your Telegram chat.

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
