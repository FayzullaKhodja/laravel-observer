<?php

namespace Khodja\LaravelObserver\Transport;

interface TransportInterface
{
    /**
     * Deliver one batch envelope to the Log Server.
     *
     * Implementations must never throw: delivery failures are swallowed so
     * observability can never break the host application.
     *
     * @param  array<string, mixed>  $batch
     */
    public function send(array $batch): void;
}
