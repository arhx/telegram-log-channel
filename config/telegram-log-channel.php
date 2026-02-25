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

];
