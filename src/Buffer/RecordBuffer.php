<?php

namespace Company\Observer\Buffer;

use Company\Observer\Transport\TransportInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Throwable;

class RecordBuffer
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    private int $recordsBytes = 0;

    private bool $flushing = false;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Application $app,
        private readonly int $maxBatchRecords,
        private readonly int $maxBatchBytes,
        private readonly bool $enabled,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public function push(array $record): void
    {
        if (! $this->accepting()) {
            return;
        }

        $encoded = json_encode(
            $record,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        $recordBytes = is_string($encoded) ? strlen($encoded) : max(1, $this->maxBatchBytes);

        if (count($this->records) >= max(1, $this->maxBatchRecords)
            || ($this->records !== [] && $this->recordsBytes + $recordBytes > max(1, $this->maxBatchBytes))) {
            $this->flush();
        }

        if ($this->accepting()) {
            $this->records[] = $record;
            $this->recordsBytes += $recordBytes;
        }
    }

    public function flush(): void
    {
        if (! $this->enabled || $this->flushing || $this->records === []) {
            return;
        }

        $this->flushing = true;

        try {
            $this->transport->send($this->envelope());
        } catch (Throwable) {
            // A third-party transport must not be able to break host code.
        } finally {
            $this->records = [];
            $this->recordsBytes = 0;
            $this->flushing = false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function clear(): void
    {
        $this->records = [];
        $this->recordsBytes = 0;
    }

    public function accepting(): bool
    {
        return $this->enabled && ! $this->flushing;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            'batch_id' => (string) Str::ulid(),
            'sent_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s.v\Z'),
            'source' => [
                'environment' => $this->limit((string) $this->app->environment(), 64),
                'app_name' => $this->limit((string) (config('observer.app_name') ?: config('app.name', 'Laravel')), 255),
                'hostname' => $this->limit(gethostname() ?: php_uname('n'), 255),
                'php_version' => $this->limit(PHP_VERSION, 64),
                'laravel_version' => $this->limit($this->app->version(), 64),
            ],
            'records' => $this->records,
        ];
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr(mb_scrub($value, 'UTF-8'), 0, $max);
    }
}
