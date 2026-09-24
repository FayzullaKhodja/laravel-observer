<?php

namespace Company\Observer\Context;

interface RequestIdStore
{
    public function get(): ?string;

    public function set(string $requestId): void;

    public function forget(): void;
}
