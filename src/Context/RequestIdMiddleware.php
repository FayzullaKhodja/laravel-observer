<?php

namespace Company\Observer\Context;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    public const REQUEST_ATTRIBUTE = '_observer_request_id';

    public function __construct(private readonly ContextProvider $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observer.request_id.middleware', true)) {
            Context::forget('request_id');
            $this->context->clearRequest();

            return $next($request);
        }

        $header = $this->headerName();
        $requestId = $this->valid($request->headers->get($header))
            ? $request->headers->get($header)
            : (string) Str::ulid();

        Context::add('request_id', $requestId);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $requestId);
        $this->context->setRequest($request);

        $response = $next($request);
        $response->headers->set($header, $requestId);

        return $response;
    }

    public static function headerName(): string
    {
        $header = trim((string) config('observer.request_id.header', 'X-Request-ID'));

        return $header !== '' ? $header : 'X-Request-ID';
    }

    private function valid(?string $requestId): bool
    {
        return $requestId !== null
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1;
    }
}
