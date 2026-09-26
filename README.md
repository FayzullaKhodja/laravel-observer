# khodja/laravel-observer

Laravel client package for the Log Observer server. Applications keep using
`Log::info()`, `Log::error()`, `report($e)`; this package adds a Monolog
handler that buffers records in memory and ships them to the Log Server in
batches. The package supports Laravel 10 through 13 and PHP 8.1 or newer.

## Install

The package is available on
[Packagist](https://packagist.org/packages/khodja/laravel-observer):

```bash
composer require khodja/laravel-observer:^1.0
php artisan vendor:publish --tag=observer-config
```

Source: [`FayzullaKhodja/laravel-observer`](https://github.com/FayzullaKhodja/laravel-observer).

For local development against a checkout of the monorepo, use a path
repository (`"type": "path"`, `"url": "../log-observer/packages/laravel-observer"`)
and `composer require khodja/laravel-observer:@dev` instead.

Package discovery registers `ObserverServiceProvider` automatically.

## Configure logging

Add an `observer` channel to `config/logging.php`, then keep it next to a
local channel in the normal Laravel stack:

```php
use Khodja\LaravelObserver\Logging\ObserverHandler;

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
OBSERVER_MAX_TRACE_LENGTH=32768
OBSERVER_MAX_CONTEXT_BYTES=32768
OBSERVER_APP_NAME=
OBSERVER_LEVEL=debug
OBSERVER_REQUEST_ID_MIDDLEWARE=true
OBSERVER_REQUEST_ID_HEADER=X-Request-ID
# Optional comma-separated override:
OBSERVER_REDACT_KEYS=password,password_confirmation,passwd,secret,client_secret,api_key,apikey,access_token,refresh_token,token,authorization,cookie,set-cookie,credit_card,card_number,cvv,cvc
```

`OBSERVER_APP_NAME` defaults to `APP_NAME`. The optional
`OBSERVER_REDACT_KEYS` value is a comma-separated list of sensitive key
fragments.

## Request and job correlation

By default, the package prepends global HTTP middleware that accepts an
`X-Request-ID` containing 1 to 128 letters, digits, `.`, `_`, `:`, or `-`.
It generates a ULID when that header is absent or invalid, adds the ID to all
observer and local Laravel logs, and returns it on the response. Set
`OBSERVER_REQUEST_ID_MIDDLEWARE=false` to disable this behavior or change the
header with `OBSERVER_REQUEST_ID_HEADER`.

Observer records include the request method, query-redacted path, route name,
and only an already-resolved authenticated user ID. Bodies, headers, cookies,
IP addresses, and user profile fields are not collected. On Laravel 11 and
newer, Laravel Context automatically carries the request ID into jobs
dispatched during the request. Laravel 10 has no Context dehydration,
so request IDs are not propagated into queued jobs automatically. Request and
local-log correlation still work on Laravel 10, and records produced while any
job runs still contain its resolved name and queue under `context._job`.

## Exception capture

Exceptions reported through Laravel's normal `report($e)` flow, unhandled
exceptions, and throwable values passed as `context['exception']` are captured
without a package-specific API. The observer record includes the exception
class, message, code, file, line, and an argument-free stack trace. Previous
exceptions are included up to five levels deep.

Exception messages use `OBSERVER_MAX_MESSAGE_LENGTH`. Stack traces are limited
by `OBSERVER_MAX_TRACE_LENGTH` (32,768 characters by default) and marked when
truncated. The extracted throwable is removed from `context`; other exception
context supplied through Laravel is normalized and redacted as usual.

## Limits and delivery behavior

Records are normalized, recursively redacted, and held in memory. The package
sends one batch when the application terminates or a queue job/console command
finishes. When `OBSERVER_MAX_BATCH_RECORDS` or `OBSERVER_MAX_BATCH_BYTES` is
reached, it sends that full batch early and continues with a fresh buffer so
long workers stay bounded. A single record whose JSON-encoded size exceeds
`max(1, OBSERVER_MAX_BATCH_BYTES)` is dropped before buffering and is never
sent. The drop is silent to avoid recursive Laravel logging. The byte limit
leaves room under the server's default 2 MB request limit for the envelope.

Delivery uses short connect/request timeouts, no retries, and no persistent
client queue. Failed deliveries and non-success responses are dropped without
affecting application code. Logging during delivery is ignored to prevent
recursive observer requests. Setting `OBSERVER_ENABLED=false`, or omitting the
URL or token, makes the handler a no-op.

## License

MIT. See [`LICENSE`](LICENSE).
