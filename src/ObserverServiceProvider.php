<?php

namespace Company\Observer;

use Company\Observer\Buffer\RecordBuffer;
use Company\Observer\Logging\RecordNormalizer;
use Company\Observer\Security\DataSanitizer;
use Company\Observer\Transport\HttpTransport;
use Company\Observer\Transport\TransportInterface;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;

class ObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observer.php', 'observer');

        $this->app->singleton(DataSanitizer::class, fn () => new DataSanitizer(
            config('observer.redact_keys', []),
        ));

        $this->app->singleton(RecordNormalizer::class, fn ($app) => new RecordNormalizer(
            $app->make(DataSanitizer::class),
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

        if (! config('observer.enabled')) {
            return;
        }

        $flush = fn () => $this->app->make(RecordBuffer::class)->flush();

        $this->app->terminating($flush);

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, CommandFinished::class] as $event) {
            $this->app->make(Dispatcher::class)->listen($event, $flush);
        }
    }

    private function deliveryIsConfigured(): bool
    {
        return (bool) config('observer.enabled')
            && trim((string) config('observer.url')) !== ''
            && trim((string) config('observer.token')) !== '';
    }
}
