# company/laravel-observer

Laravel client package for the Log Observer server. Applications keep using
`Log::info()`, `Log::error()`, `report($e)`; this package adds a Monolog
handler that buffers records in memory and ships them to the Log Server in
batches.

Status: skeleton (Phase 0). Handler, buffer, transport and request context
arrive in Phases 4-6. See the root `IMPLEMENTATION_PLAN.md`.
