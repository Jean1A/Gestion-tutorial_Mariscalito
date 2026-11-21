<?php

return [
    'webhook_enabled' => env('SCHOOL_MESSAGE_WEBHOOK_ENABLED', false),
    'webhook_url' => env('SCHOOL_MESSAGE_WEBHOOK_URL'),
    'webhook_token' => env('SCHOOL_MESSAGE_WEBHOOK_TOKEN'),
    'channel' => env('SCHOOL_MESSAGE_CHANNEL', 'whatsapp'),
    'restore_binary' => env('SCHOOL_RESTORE_BINARY'),
];
