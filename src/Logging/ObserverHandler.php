<?php

namespace Khodja\LaravelObserver\Logging;

use Khodja\LaravelObserver\Buffer\RecordBuffer;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LogLevel;
use Throwable;

class ObserverHandler extends AbstractHandler
{
    /**
     * @param  int|string|Level|LogLevel::*  $level
     */
    public function __construct(
        private readonly RecordBuffer $buffer,
        private readonly RecordNormalizer $normalizer,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record) || ! $this->buffer->accepting()) {
            return false;
        }

        try {
            $this->buffer->push($this->normalizer->normalize($record));
        } catch (Throwable) {
            // Logging must never fail because the observer cannot normalize.
        }

        return ! $this->getBubble();
    }

    public function close(): void
    {
        $this->buffer->flush();
    }
}
