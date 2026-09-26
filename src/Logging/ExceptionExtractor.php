<?php

namespace Khodja\LaravelObserver\Logging;

use Throwable;

class ExceptionExtractor
{
    private const MAX_PREVIOUS_EXCEPTIONS = 5;

    public function __construct(
        private readonly int $maxMessageLength,
        private readonly int $maxTraceLength,
    ) {}

    /**
     * @return array{class: string, message: string, code: int|string|null, file: string, line: int, trace: string}|null
     */
    public function extract(Throwable $exception): ?array
    {
        try {
            return [
                'class' => $this->limit(get_class($exception), 255),
                'message' => $this->truncate($this->validUtf8($exception->getMessage()), $this->maxMessageLength),
                'code' => is_int($exception->getCode()) || is_string($exception->getCode())
                    ? $exception->getCode()
                    : null,
                'file' => $this->limit($exception->getFile(), 2048),
                'line' => max(0, $exception->getLine()),
                'trace' => $this->truncate($this->traceChain($exception), $this->maxTraceLength),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function traceChain(Throwable $exception): string
    {
        $parts = [$this->trace($exception)];
        $previous = $exception->getPrevious();
        $depth = 0;

        while ($previous !== null && $depth < self::MAX_PREVIOUS_EXCEPTIONS) {
            $trace = $this->trace($previous);
            $parts[] = sprintf(
                'Caused by: %s: %s at %s:%d%s%s',
                $this->limit(get_class($previous), 255),
                $this->truncate($this->validUtf8($previous->getMessage()), $this->maxMessageLength),
                $this->limit($previous->getFile(), 2048),
                max(0, $previous->getLine()),
                $trace === '' ? '' : "\n",
                $trace,
            );

            $previous = $previous->getPrevious();
            $depth++;
        }

        if ($previous !== null) {
            $parts[] = 'Caused by: [previous exception chain truncated]';
        }

        return implode("\n\n", $parts);
    }

    private function trace(Throwable $exception): string
    {
        $lines = [];

        foreach ($exception->getTrace() as $index => $frame) {
            $location = isset($frame['file'])
                ? $this->validUtf8((string) $frame['file']).'('.max(0, (int) ($frame['line'] ?? 0)).')'
                : '[internal function]';

            $call = $this->validUtf8(
                (string) ($frame['class'] ?? '')
                .(string) ($frame['type'] ?? '')
                .(string) ($frame['function'] ?? '{main}'),
            );

            $lines[] = sprintf('#%d %s: %s()', $index, $location, $call);
        }

        return implode("\n", $lines);
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr($this->validUtf8($value), 0, max(0, $max));
    }

    private function truncate(string $value, int $max): string
    {
        $max = max(0, $max);

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $markerLength = mb_strlen(RecordNormalizer::TRUNCATED);

        return $max <= $markerLength
            ? mb_substr(RecordNormalizer::TRUNCATED, 0, $max)
            : mb_substr($value, 0, $max - $markerLength).RecordNormalizer::TRUNCATED;
    }

    private function validUtf8(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
