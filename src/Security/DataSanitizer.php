<?php

namespace Company\Observer\Security;

/**
 * Recursively redacts values whose keys contain sensitive fragments.
 */
class DataSanitizer
{
    public const REDACTED = '[REDACTED]';

    public const MAX_DEPTH = 10;

    /** @var list<string> */
    private readonly array $fragments;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        $this->fragments = array_values(array_unique(array_map(
            fn (string $key) => $this->normalizeKey($key),
            $keys,
        )));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function sanitize(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => 'max depth reached'];
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $sanitized[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value, $depth + 1);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public function isSensitive(string $key): bool
    {
        $key = $this->normalizeKey($key);

        foreach ($this->fragments as $fragment) {
            if ($fragment !== '' && str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function redactPath(string $path): string
    {
        $queryPosition = strpos($path, '?');

        if ($queryPosition === false) {
            return $path;
        }

        $pairs = explode('&', substr($path, $queryPosition + 1));

        foreach ($pairs as $index => $pair) {
            [$encodedName] = explode('=', $pair, 2);
            $name = urldecode($encodedName);

            if ($name !== '' && $this->isSensitive($name)) {
                $pairs[$index] = $encodedName.'='.self::REDACTED;
            }
        }

        return substr($path, 0, $queryPosition + 1).implode('&', $pairs);
    }

    private function normalizeKey(string $key): string
    {
        return str_replace('-', '_', strtolower($key));
    }
}
