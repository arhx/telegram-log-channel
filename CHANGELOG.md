# Changelog

All notable changes to this project will be documented in this file.

## [1.1.1] - 2026-02-25

### Fixed
- Queue failures setting now reads from a dedicated config file via `config()` instead of `env()` directly, ensuring compatibility with `php artisan config:cache`.
- Added `mergeConfigFrom` in `register()` so default values work without publishing.
- Added `publishes()` so users can publish `config/telegram-log-channel.php` for production use with config cache.

## [1.1.0] - 2026-02-25

### Added
- Automatic Telegram notification when a queued job fails (`Queue::failing` listener).
- Notifications include job name, exception message, connection name, and queue name.
- Controlled via `TELEGRAM_LOG_QUEUE_FAILURES` env variable (defaults to `true`).
- Compatible with Laravel 10, 11, and 12.

## [1.0.5] - 2026-01-24

### Added
- Added unit tests for `TelegramHandler` to verify recursion protection and error handling.
- Added `autoload-dev` to `composer.json` for test support.

### Fixed
- Added protection against recursive logging in `TelegramHandler`.
- Added `try-catch` block in `TelegramHandler::write` to handle exceptions during log sending.
- Implemented `logSelfError` to log internal errors to the `single` channel or system log without recursion.
