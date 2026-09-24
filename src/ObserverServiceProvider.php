<?php

namespace Company\Observer;

use Company\Observer\Buffer\RecordBuffer;
use Company\Observer\Context\ContextProvider;
use Company\Observer\Context\RequestIdMiddleware;
use Company\Observer\Logging\ExceptionExtractor;
use Company\Observer\Logging\RecordNormalizer;
use Company\Observer\Security\DataSanitizer;
use Company\Observer\Transport\HttpTransport;
use Company\Observer\Transport\TransportInterface;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;

class ObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observer.php', 'observer');

        $this->app->singleton(DataSanitizer::class, fn () => new DataSanitizer(
            config('observer.redact_keys', []),
        ));

        $this->app->singleton(ContextProvider::class, fn ($app) => new ContextProvider(
            $app->make(AuthFactory::class),
            $app->make(DataSanitizer::class),
        ));

        $this->app->singleton(ExceptionExtractor::class, fn () => new ExceptionExtractor(
            (int) config('observer.max_message_length', 8192),
            (int) config('observer.max_trace_length', 32768),
        ));

        $this->app->singleton(RecordNormalizer::class, fn ($app) => new RecordNormalizer(
            $app->make(DataSanitizer::class),
            $app->make(ContextProvider::class),
            $app->make(ExceptionExtractor::class),
            (int) config('observer.max_message_length', 8192),
            (int) config('observer.max_context_bytes', 32768),
        ));

        $this->app->singleton(TransportInterface::class, fn () => new HttpTransport(
            trim((string) config('observer.url', '')),
            trim((string) config('observer.token', '')),
            (int) config('observer.timeout_ms', 500),
            (int) config('observer.connect_timeout_ms', 200),
        ));

        $this->app->singleton(RecordBuffer::class, fn ($app) => new RecordBuffer(
            $app->make(TransportInterface::class),
            $app,
            (int) config('observer.max_batch_records', 250),
            (int) config('observer.max_batch_bytes', 1500000),
            $this->deliveryIsConfigured(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/observer.php' => config_path('observer.php'),
            ], 'observer-config');
        }

        $this->registerRequestIdMiddleware();

        if (! config('observer.enabled')) {
            $this->app->terminating(fn () => $this->clearRequestContext());

            return;
        }

        $flush = fn () => $this->app->make(RecordBuffer::class)->flush();
        $events = $this->app->make(Dispatcher::class);

        $this->app->terminating($flush);
        $this->app->terminating(fn () => $this->clearRequestContext());

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(ContextProvider::class)->setJob($event->job);
        });

        foreach ([JobProcessed::class, JobFailed::class] as $event) {
            $events->listen($event, function () use ($flush): void {
                $flush();
                $this->app->make(ContextProvider::class)->clearJob();
            });
        }

        $events->listen(JobExceptionOccurred::class, $flush);
        $events->listen(CommandFinished::class, $flush);
    }

    private function deliveryIsConfigured(): bool
    {
        return (bool) config('observer.enabled')
            && trim((string) config('observer.url')) !== ''
            && trim((string) config('observer.token')) !== '';
    }

    private function registerRequestIdMiddleware(): void
    {
        if (! config('observer.request_id.middleware', true) || ! $this->app->bound(HttpKernel::class)) {
            return;
        }

        $kernel = $this->app->make(HttpKernel::class);

        if (! method_exists($kernel, 'prependMiddleware')) {
            return;
        }

        $kernel->prependMiddleware(RequestIdMiddleware::class);

        $this->app->make(Dispatcher::class)->listen(
            RequestHandled::class,
            function (RequestHandled $event): void {
                $requestId = $event->request->attributes->get(RequestIdMiddleware::REQUEST_ATTRIBUTE);

                if (is_string($requestId) && $requestId !== '') {
                    $event->response->headers->set(RequestIdMiddleware::headerName(), $requestId);
                }
            },
        );
    }

    private function clearRequestContext(): void
    {
        Context::forget('request_id');
        $this->app->make(ContextProvider::class)->clearRequest();
    }
}
