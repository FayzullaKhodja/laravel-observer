<?php

namespace Khodja\LaravelObserver\Context;

use Illuminate\Support\Facades\Log;
use Throwable;

class SharedLogRequestIdStore implements RequestIdStore
{
    private const KEY = 'request_id';

    private ?string $requestId = null;

    public function get(): ?string
    {
        return $this->requestId;
    }

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;

        try {
            $manager = Log::getFacadeRoot();

            if (is_object($manager) && method_exists($manager, 'shareContext')) {
                Log::shareContext([self::KEY => $requestId]);
            }
        } catch (Throwable) {
            // Request correlation must never break the host application.
        }
    }

    public function forget(): void
    {
        $this->requestId = null;

        try {
            $manager = Log::getFacadeRoot();

            if (! is_object($manager)) {
                return;
            }

            if (! method_exists($manager, 'sharedContext')
                || ! method_exists($manager, 'withoutContext')
                || ! method_exists($manager, 'flushSharedContext')) {
                return;
            }

            $shared = $manager->sharedContext();
            $shared = is_array($shared) ? $shared : [];

            unset($shared[self::KEY]);

            $manager->withoutContext();
            $manager->flushSharedContext();

            if ($shared !== [] && method_exists($manager, 'shareContext')) {
                Log::shareContext($shared);
            }
        } catch (Throwable) {
            // Older or custom log managers may not support shared context.
        }
    }
}
