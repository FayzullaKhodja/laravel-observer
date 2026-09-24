<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Observer delivery
    |--------------------------------------------------------------------------
    |
    | Records are buffered in memory and sent to the central Log Server in a
    | single batch per request/job. Delivery failures are swallowed so they
    | never affect the host application. Full behaviour lands in Phase 4.
    |
    */

    'enabled' => (bool) env('OBSERVER_ENABLED', false),

    'url' => env('OBSERVER_URL'),

    'token' => env('OBSERVER_TOKEN'),

    'timeout_ms' => (int) env('OBSERVER_TIMEOUT_MS', 500),

    'connect_timeout_ms' => (int) env('OBSERVER_CONNECT_TIMEOUT_MS', 200),

    'max_batch_records' => (int) env('OBSERVER_MAX_BATCH_RECORDS', 250),

];
