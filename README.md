# Laravel Telegram Log Channel

A simple Laravel package to send log messages to a Telegram chat.

## Installation

You can install the package via composer:

```bash
composer require arhx/telegram-log-channel
```

## Setup

1.  Publish the configuration file:

    ```bash
    php artisan vendor:publish --provider="Arhx\TelegramLogChannel\TelegramLogChannelServiceProvider" --tag="config"
    ```

2.  Add the necessary environment variables to your `.env` file:

    ```env
    TELEGRAM_LOG_BOT_TOKEN=your_bot_token_here
    TELEGRAM_LOG_CHAT_ID=your_chat_id_here
    TELEGRAM_LOG_LEVEL=error
    ```

    -   `TELEGRAM_LOG_BOT_TOKEN`: Your Telegram bot's token.
    -   `TELEGRAM_LOG_CHAT_ID`: The ID of the chat where logs should be sent.
    -   `TELEGRAM_LOG_LEVEL`: The minimum log level to be sent (e.g., debug, info, notice, warning, error, critical, alert, emergency).

## Usage

Add a new channel to your `config/logging.php` configuration file:

```php
'channels' => [
    // ... other channels

    'telegram' => [
        'driver' => 'telegram',
        'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
        'level' => env('TELEGRAM_LOG_LEVEL', 'error'),
    ],
],
```

You can then add this channel to your logging stack to receive notifications. For example, in `config/logging.php`:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'telegram'], // Add 'telegram' here
    'ignore_exceptions' => false,
],
```

Now, any log message with a level of `error` or higher will be sent to your Telegram chat.
