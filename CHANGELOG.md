# Changelog

All notable changes to this project will be documented in this file.

## [1.0.5] - 2026-01-24

### Added
- Added unit tests for `TelegramHandler` to verify recursion protection and error handling.
- Added `autoload-dev` to `composer.json` for test support.

### Fixed
- Added protection against recursive logging in `TelegramHandler`.
- Added `try-catch` block in `TelegramHandler::write` to handle exceptions during log sending.
- Implemented `logSelfError` to log internal errors to the `single` channel or system log without recursion.
