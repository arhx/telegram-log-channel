<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Job Failure Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will automatically send a Telegram notification
    | whenever a queued job fails. Set to false to disable this behaviour.
    |
    */

    'queue_failures' => env('TELEGRAM_LOG_QUEUE_FAILURES', true),

    /*
    |--------------------------------------------------------------------------
    | TLS Certificate Verification
    |--------------------------------------------------------------------------
    |
    | Verify the TLS certificate of api.telegram.org (Guzzle `verify` option).
    | Keep it enabled: without it the bot token and log contents can be
    | intercepted. A string value is treated as a path to a CA bundle.
    | A channel may override this with its own `verify` key in logging.php.
    |
    */

    'verify_tls' => env('TELEGRAM_LOG_VERIFY_TLS', true),

];
