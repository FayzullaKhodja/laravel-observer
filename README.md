# company/laravel-observer

Laravel client package for the Log Observer server. Applications keep using
`Log::info()`, `Log::error()`, `report($e)`; this package adds a Monolog
handler that buffers records in memory and ships them to the Log Server in
batches.

## Install

Add the package repository and require the package:

```bash
composer require company/laravel-observer
php artisan vendor:publish --tag=observer-config
```

Package discovery registers `ObserverServiceProvider` automatically.

## Configure logging

Add an `observer` channel to `config/logging.php`, then keep it next to a
local channel in the normal Laravel stack:

```php
use Company\Observer\Logging\ObserverHandler;

'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'observer'],
        'ignore_exceptions' => false,
    ],

    'observer' => [
        'driver' => 'monolog',
        'handler' => ObserverHandler::class,
        'level' => env('OBSERVER_LEVEL', 'debug'),
    ],

    // Keep the standard daily channel configured here.
],
```

Do not replace the local `daily` channel. Application code continues to use
Laravel's standard logger:

```php
Log::info('Order created', ['order_id' => $order->id]);
Log::error('Order sync failed', ['order_id' => $order->id]);
```

## Environment

```env
OBSERVER_ENABLED=true
OBSERVER_URL=https://logs.example.com
OBSERVER_TOKEN=lop_replace_with_project_token
OBSERVER_TIMEOUT_MS=500
OBSERVER_CONNECT_TIMEOUT_MS=200
OBSERVER_MAX_BATCH_RECORDS=250
OBSERVER_MAX_BATCH_BYTES=1500000
OBSERVER_MAX_MESSAGE_LENGTH=8192
OBSERVER_MAX_CONTEXT_BYTES=32768
OBSERVER_APP_NAME=
OBSERVER_LEVEL=debug
# Optional comma-separated override:
OBSERVER_REDACT_KEYS=password,password_confirmation,passwd,secret,client_secret,api_key,apikey,access_token,refresh_token,token,authorization,cookie,set-cookie,credit_card,card_number,cvv,cvc
```

`OBSERVER_APP_NAME` defaults to `APP_NAME`. The optional
`OBSERVER_REDACT_KEYS` value is a comma-separated list of sensitive key
fragments.

## Delivery behavior

Records are normalized, recursively redacted, and held in memory. The package
sends one batch when the application terminates or a queue job/console command
finishes. When `OBSERVER_MAX_BATCH_RECORDS` or `OBSERVER_MAX_BATCH_BYTES` is
reached, it sends that full batch early and continues with a fresh buffer so
long workers stay bounded. The byte limit leaves room under the server's
default 2 MB request limit for the envelope.

Delivery uses short connect/request timeouts, no retries, and no persistent
client queue. Failed deliveries and non-success responses are dropped without
affecting application code. Logging during delivery is ignored to prevent
recursive observer requests. Setting `OBSERVER_ENABLED=false`, or omitting the
URL or token, makes the handler a no-op.

Request/user correlation is added in Phase 5. Exception field extraction is
added in Phase 6; until then, throwable context is safely normalized inside
`context`.
