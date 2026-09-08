<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Throwable;

/**
 * Attaches the recorder to Laravel, without any app having to change a line.
 *
 * Both hooks are events rather than the more obvious alternatives, and in both
 * cases the obvious alternative was worse:
 *
 * - **Errors via `MessageLogged`, not a custom exception handler.** A package
 *   cannot reach into an app's `bootstrap/app.php` `withExceptions()` block,
 *   and asking thirteen apps to each add a line is thirteen chances to forget.
 *   Laravel's own handler reports every unhandled exception through the logger
 *   with the throwable in `context.exception`, so listening to the log catches
 *   those *and* the deliberate `Log::error()` calls a handler would never see.
 *   One hook, both kinds, no app-side wiring.
 *
 * - **Requests via `RequestHandled`, not middleware.** Global middleware
 *   pushed from a package lands at whatever position the stack happens to give
 *   it, so it measures a different slice of the request in each app, and it
 *   misses anything that short-circuits above it. `RequestHandled` fires once,
 *   after the response is made, in every app, at the same point.
 */
final class Listeners
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Config $config,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, $this->onMessageLogged(...));
        $events->listen(RequestHandled::class, $this->onRequestHandled(...));
    }

    /**
     * A log line at error level or worse.
     *
     * Only ERROR and above. Warnings are the level applications use for
     * "something to look at eventually", and a monitor that reports those with
     * the same weight as a 500 trains its reader to ignore it.
     */
    private function onMessageLogged(MessageLogged $event): void
    {
        if (! in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            return;
        }

        $throwable = $event->context['exception'] ?? null;

        $this->recorder->error(
            level: $event->level,
            message: $event->message,
            throwable: $throwable instanceof Throwable ? $throwable : null,
        );
    }

    /** One finished request. */
    private function onRequestHandled(RequestHandled $event): void
    {
        if (! $this->config->watchesRequests()) {
            return;
        }

        $path = trim($event->request->path(), '/');

        foreach ($this->config->ignoredPaths() as $ignored) {
            if (Str::is(trim($ignored, '/'), $path)) {
                return;
            }
        }

        // The route pattern when one matched. A 404 has no route, and its
        // actual path is the one thing worth knowing about it — but the path
        // may carry ids, so unmatched requests are collapsed into a single
        // bucket rather than each becoming its own row. Otherwise a scanner
        // spraying URLs writes a row per attempt.
        $route = $event->request->route()?->uri();
        $route = $route !== null ? '/'.ltrim($route, '/') : '(unmatched)';

        $this->recorder->request(
            method: $event->request->method(),
            route: $route,
            durationMs: $this->elapsedMs(),
            status: $event->response->getStatusCode(),
        );
    }

    /**
     * How long the request took, from the framework's own start constant.
     *
     * `LARAVEL_START` is set in `public/index.php` before the framework boots,
     * so it measures what the visitor actually waited for rather than what the
     * middleware stack saw. Absent under some SAPIs and in tests, where the
     * duration is honestly reported as zero rather than invented from the
     * moment this listener happened to run.
     */
    private function elapsedMs(): int
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : null;

        return $start === null ? 0 : (int) round((microtime(true) - $start) * 1000);
    }
}
