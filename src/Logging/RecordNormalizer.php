<?php

namespace Company\Observer\Logging;

use Company\Observer\Context\ContextProvider;
use Company\Observer\Security\DataSanitizer;
use DateTimeZone;
use Illuminate\Support\Str;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Throwable;

class RecordNormalizer
{
    public const TRUNCATED = '…[truncated]';

    private const MAX_CONTEXT_STRING = 1024;

    private readonly NormalizerFormatter $formatter;

    public function __construct(
        private readonly DataSanitizer $sanitizer,
        private readonly ContextProvider $contextProvider,
        private readonly ExceptionExtractor $exceptionExtractor,
        private readonly int $maxMessageLength,
        private readonly int $maxContextBytes,
    ) {
        $this->formatter = (new NormalizerFormatter)
            ->setMaxNormalizeDepth(DataSanitizer::MAX_DEPTH)
            ->setMaxNormalizeItemCount(1000);
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(LogRecord $record): array
    {
        $captured = $this->contextProvider->capture();
        $context = $record->context;
        $exception = null;

        if (($context['exception'] ?? null) instanceof Throwable) {
            try {
                $exception = $this->exceptionExtractor->extract($context['exception']);

                if ($exception !== null) {
                    unset($context['exception']);
                }
            } catch (Throwable) {
                $exception = null;
            }
        }

        return [
            'record_id' => (string) Str::ulid(),
            'timestamp' => $record->datetime
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
            'level' => strtolower($record->level->getName()),
            'message' => $this->truncate($this->validUtf8($record->message), $this->maxMessageLength),
            'context' => $this->normalizeContext($context, $record->extra, $captured['job']),
            'request_id' => $captured['request_id'],
            'request' => $captured['request'],
            'user' => $captured['user'],
            'exception' => $exception,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     * @param  array{name: string|null, queue: string|null}|null  $job
     * @return array<array-key, mixed>
     */
    private function normalizeContext(array $context, array $extra, ?array $job): array
    {
        unset($extra['request_id']);

        if ($extra !== []) {
            $context['_extra'] = $extra;
        }

        if ($job !== null) {
            $context['_job'] = $job;
        }

        try {
            $normalized = $this->formatter->normalizeValue($context);
            $normalized = is_array($normalized) ? $normalized : ['value' => $normalized];
            $normalized = $this->jsonSafe($normalized);
        } catch (Throwable) {
            $normalized = ['_normalization_error' => 'context could not be normalized'];
        }

        $normalized = $this->cleanStrings($normalized);
        $normalized = $this->sanitizer->sanitize($normalized);
        $originalBytes = strlen($this->encode($normalized));

        if ($originalBytes <= max(0, $this->maxContextBytes)) {
            return $normalized;
        }

        $shortened = $this->shortenStrings($normalized);
        $shortened['_truncated'] = true;

        if (strlen($this->encode($shortened)) <= max(0, $this->maxContextBytes)) {
            return $shortened;
        }

        return [
            '_truncated' => true,
            '_original_bytes' => $originalBytes,
        ];
    }

    private function truncate(string $value, int $max): string
    {
        $max = max(0, $max);

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $markerLength = mb_strlen(self::TRUNCATED);

        return $max <= $markerLength
            ? mb_substr(self::TRUNCATED, 0, $max)
            : mb_substr($value, 0, $max - $markerLength).self::TRUNCATED;
    }

    private function validUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return json_decode(
            json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function cleanStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->validUtf8($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $cleaned = [];

        foreach ($value as $key => $item) {
            $cleaned[is_string($key) ? $this->validUtf8($key) : $key] = $this->cleanStrings($item);
        }

        return $cleaned;
    }

    private function jsonSafe(mixed $value, int $depth = 0): mixed
    {
        if ($depth > DataSanitizer::MAX_DEPTH) {
            return 'max depth reached';
        }

        if (is_array($value)) {
            $safe = [];

            foreach ($value as $key => $item) {
                $safe[$key] = $this->jsonSafe($item, $depth + 1);
            }

            return $safe;
        }

        if (is_object($value) || is_resource($value)) {
            return $this->jsonSafe($this->formatter->normalizeValue($value), $depth + 1);
        }

        if (is_float($value) && ! is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? 'INF' : '-INF');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function shortenStrings(array $data): array
    {
        return array_map(fn ($value) => match (true) {
            is_array($value) => $this->shortenStrings($value),
            is_string($value) => $this->truncate($value, self::MAX_CONTEXT_STRING),
            default => $value,
        }, $data);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
    }
}
