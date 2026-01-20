<?php

return [
    'token' => env('TELEGRAM_LOG_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),
    'level' => env('TELEGRAM_LOG_LEVEL', 'error'),
];
