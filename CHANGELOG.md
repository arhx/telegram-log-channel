# Changelog

All notable changes to this project will be documented in this file.

## [1.5.0] - 2026-08-11

### Added
- **Forum topic support.** A channel can now declare `topic_id` (env `TELEGRAM_LOG_TOPIC_ID`), sent to the Bot API as `message_thread_id`, so logs land in a specific topic of a forum supergroup instead of *General*. Empty / unset → the key is omitted entirely, so plain groups and channels behave exactly as before.
- Several channels may run on the same bot with different `topic_id`s (any channel can use the `telegram` driver), which is how one bot serves e.g. an `ERROR` topic and an `UPDATES` topic.
- `telegram-log:test` accepts `--channel=` to test any channel using the telegram driver (defaults to `telegram`).

### Changed
- The duplicate-suppression signature now includes chat id and topic id, so the same message going to two different topics is no longer cross-throttled.

## [1.4.0] - 2026-07-20

### Added
- Guzzle 8 support: the `guzzlehttp/guzzle` constraint is now `^7.0|^8.0`. No code changes were required — the client options (`verify`, `timeout`, `connect_timeout`) and the `post()` JSON send path behave identically on both majors.

### Notes
- `laravel/framework` still pins `guzzlehttp/guzzle ^7.8.2` (as of v13.20.0), so a standard Laravel app will keep resolving Guzzle 7 until the framework widens its own constraint. This release simply stops the package from being the blocker.

## [1.3.0] - 2026-06-06

### Added
- Spam throttling: identical messages (same level, text, and source file:line) are now suppressed for a configurable window via the `Cache` facade. Controlled by `TELEGRAM_LOG_THROTTLE` (defaults to `600` seconds = 10 minutes; `0` disables it).

### Fixed
- Added Guzzle `timeout` (5s) and `connect_timeout` (3s) so an unreachable Telegram API no longer blocks the request/queue worker indefinitely.

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
