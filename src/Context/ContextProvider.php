<?php

namespace Company\Observer\Context;

use Company\Observer\Security\DataSanitizer;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Throwable;

class ContextProvider
{
    private ?Request $request = null;

    /** @var array{name: string|null, queue: string|null}|null */
    private ?array $job = null;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly DataSanitizer $sanitizer,
    ) {}

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function clearRequest(): void
    {
        $this->request = null;
    }

    public function setJob(Job $job): void
    {
        try {
            $this->job = [
                'name' => $this->limit((string) $job->resolveName(), 255),
                'queue' => $this->limit((string) $job->getQueue(), 255),
            ];
        } catch (Throwable) {
            $this->job = null;
        }
    }

    public function clearJob(): void
    {
        $this->job = null;
    }

    /**
     * @return array{
     *     request_id: string|null,
     *     request: array{method: string, path: string, route: string|null}|null,
     *     user: array{id: int|string}|null,
     *     job: array{name: string|null, queue: string|null}|null
     * }
     */
    public function capture(): array
    {
        try {
            return [
                'request_id' => $this->requestId(),
                'request' => $this->request(),
                'user' => $this->user(),
                'job' => $this->job,
            ];
        } catch (Throwable) {
            return [
                'request_id' => null,
                'request' => null,
                'user' => null,
                'job' => null,
            ];
        }
    }

    private function requestId(): ?string
    {
        $requestId = Context::get('request_id');

        if (! is_string($requestId) || $requestId === '') {
            return null;
        }

        return $this->limit($requestId, 128);
    }

    /**
     * @return array{method: string, path: string, route: string|null}|null
     */
    private function request(): ?array
    {
        if ($this->request === null) {
            return null;
        }

        $route = $this->request->route()?->getName();

        return [
            'method' => $this->limit($this->request->method(), 16),
            'path' => $this->limit(
                $this->sanitizer->redactPath($this->request->getRequestUri()),
                2048,
            ),
            'route' => is_string($route) ? $this->limit($route, 255) : null,
        ];
    }

    /**
     * @return array{id: int|string}|null
     */
    private function user(): ?array
    {
        $guard = $this->auth->guard();

        if (! $guard->hasUser()) {
            return null;
        }

        $id = $guard->id();

        if (is_string($id)) {
            return ['id' => $this->limit($id, 64)];
        }

        return is_int($id) ? ['id' => $id] : null;
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr(mb_scrub($value, 'UTF-8'), 0, $max);
    }
}
