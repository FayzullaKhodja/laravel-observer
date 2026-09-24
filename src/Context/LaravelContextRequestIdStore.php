<?php

namespace Company\Observer\Context;

use Illuminate\Support\Facades\Context;

class LaravelContextRequestIdStore implements RequestIdStore
{
    private const KEY = 'request_id';

    public function get(): ?string
    {
        $requestId = Context::get(self::KEY);

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    public function set(string $requestId): void
    {
        Context::add(self::KEY, $requestId);
    }

    public function forget(): void
    {
        Context::forget(self::KEY);
    }
}
