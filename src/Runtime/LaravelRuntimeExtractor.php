<?php

declare(strict_types=1);

namespace Laragraph\Runtime;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Routing\Router;
use Laragraph\Support\Edge;

/**
 * Reads the wiring Laravel has already resolved at runtime — the part no
 * syntax-based tool can see. Edges are tagged resolved_by=runtime.
 *
 *   route  →  controller::method      (HTTP entrypoints)
 *   event  →  listener::method        (event/listener graph)
 *   model  →  observer::method        (Model::observe, via eloquent.* events)
 */
final class LaravelRuntimeExtractor
{
    public function __construct(
        private readonly Router $router,
        private readonly EventDispatcher $events,
    ) {
    }

    /**
     * @return iterable<Edge>
     */
    public function edges(): iterable
    {
        yield from $this->routeEdges();
        yield from $this->listenerEdges();
    }

    /**
     * @return iterable<Edge>
     */
    private function routeEdges(): iterable
    {
        foreach ($this->router->getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue; // Closure / invokable — no controller method to link
            }

            [$controller, $method] = explode('@', $action, 2);
            $signature = implode('|', $route->methods()).' /'.ltrim($route->uri(), '/');

            yield new Edge(
                $signature,
                '@route',
                $controller,
                $method,
                0,
                'route',
                'runtime',
                'route',
                'method',
            );
        }
    }

    /**
     * @return iterable<Edge>
     */
    private function listenerEdges(): iterable
    {
        if (! method_exists($this->events, 'getRawListeners')) {
            return;
        }

        foreach ($this->events->getRawListeners() as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                $target = $this->resolveListener($listener);
                if ($target === null) {
                    continue; // Closure / object listener — anonymous
                }

                [$toClass, $toMethod] = $target;
                [$fromClass, $fromMethod, $kind, $fromNodeKind] = $this->resolveEvent((string) $event);

                yield new Edge(
                    $fromClass,
                    $fromMethod,
                    $toClass,
                    $toMethod,
                    0,
                    $kind,
                    'runtime',
                    $fromNodeKind,
                    'method',
                );
            }
        }
    }

    /**
     * @return array{string, string}|null [class, method]
     */
    private function resolveListener(mixed $listener): ?array
    {
        if (is_string($listener)) {
            if (str_contains($listener, '@')) {
                [$class, $method] = explode('@', $listener, 2);

                return [$class, $method];
            }

            return [$listener, 'handle']; // invokable / ShouldQueue listener
        }

        if (is_array($listener) && count($listener) === 2 && is_string($listener[0]) && is_string($listener[1])) {
            return [$listener[0], $listener[1]];
        }

        return null;
    }

    /**
     * @return array{string, string, string, string} [fromClass, fromMethod, kind, fromNodeKind]
     */
    private function resolveEvent(string $event): array
    {
        // "eloquent.saved: App\Models\User" — a Model::observe() registration
        if (str_starts_with($event, 'eloquent.')) {
            $rest = substr($event, strlen('eloquent.'));
            [$action, $model] = array_pad(explode(': ', $rest, 2), 2, '');

            return [$model !== '' ? $model : $event, $action, 'observe', 'model-event'];
        }

        return [$event, '@event', 'event', 'event'];
    }
}
