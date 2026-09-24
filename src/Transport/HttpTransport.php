<?php

namespace Company\Observer\Transport;

use Illuminate\Support\Facades\Http;
use Throwable;

class HttpTransport implements TransportInterface
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeoutMs,
        private readonly int $connectTimeoutMs,
    ) {}

    public function send(array $batch): void
    {
        if ($this->url === '' || $this->token === '') {
            return;
        }

        try {
            Http::withToken($this->token)
                ->acceptJson()
                ->withOptions([
                    'timeout' => max(1, $this->timeoutMs) / 1000,
                    'connect_timeout' => max(1, $this->connectTimeoutMs) / 1000,
                ])
                ->post(rtrim($this->url, '/').'/api/v1/ingest', $batch);
        } catch (Throwable) {
            // V1 deliberately drops failed deliveries. There is no client retry queue.
        }
    }
}
