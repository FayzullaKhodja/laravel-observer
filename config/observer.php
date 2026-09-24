<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Observer delivery
    |--------------------------------------------------------------------------
    |
    | Records are buffered in memory and sent to the central Log Server in a
    | single batch per request/job. Delivery failures are swallowed so they
    | never affect the host application.
    |
    */

    'enabled' => (bool) env('OBSERVER_ENABLED', false),

    'url' => env('OBSERVER_URL'),

    'token' => env('OBSERVER_TOKEN'),

    'timeout_ms' => (int) env('OBSERVER_TIMEOUT_MS', 500),

    'connect_timeout_ms' => (int) env('OBSERVER_CONNECT_TIMEOUT_MS', 200),

    'max_batch_records' => (int) env('OBSERVER_MAX_BATCH_RECORDS', 250),

    // Kept below the Log Server's default 2 MB request limit for envelope overhead.
    'max_batch_bytes' => (int) env('OBSERVER_MAX_BATCH_BYTES', 1500000),

    'max_message_length' => (int) env('OBSERVER_MAX_MESSAGE_LENGTH', 8192),

    'max_context_bytes' => (int) env('OBSERVER_MAX_CONTEXT_BYTES', 32768),

    'app_name' => env('OBSERVER_APP_NAME'),

    'level' => env('OBSERVER_LEVEL', 'debug'),

    /*
    |--------------------------------------------------------------------------
    | Request correlation
    |--------------------------------------------------------------------------
    */

    'request_id' => [
        'middleware' => (bool) env('OBSERVER_REQUEST_ID_MIDDLEWARE', true),
        'header' => env('OBSERVER_REQUEST_ID_HEADER', 'X-Request-ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client-side redaction
    |--------------------------------------------------------------------------
    |
    | Keys are matched recursively and case-insensitively. A key is redacted
    | when it contains any configured fragment; "-" is treated as "_".
    |
    */

    'redact_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'OBSERVER_REDACT_KEYS',
            'password,password_confirmation,passwd,secret,client_secret,api_key,apikey,access_token,refresh_token,token,authorization,cookie,set-cookie,credit_card,card_number,cvv,cvc',
        )),
    ))),

];
