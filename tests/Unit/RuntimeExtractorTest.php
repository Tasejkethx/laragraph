<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Laragraph\Runtime\LaravelRuntimeExtractor;
use Laragraph\Support\Edge;

/**
 * @return list<Edge>
 */
function extractEdges(Router $router, Dispatcher $events): array
{
    return iterator_to_array((new LaravelRuntimeExtractor($router, $events))->edges(), false);
}

function byKind(array $edges, string $kind): array
{
    return array_values(array_filter($edges, fn (Edge $e) => $e->kind === $kind));
}

it('extracts route → controller edges', function () {
    $events = new Dispatcher();
    $router = new Router($events);
    $router->post('api/foo', 'App\Http\Controllers\FooController@store');

    $routes = byKind(extractEdges($router, $events), 'route');

    expect($routes)->toHaveCount(1)
        ->and($routes[0]->toClass)->toBe('App\Http\Controllers\FooController')
        ->and($routes[0]->toMethod)->toBe('store')
        ->and($routes[0]->resolvedBy)->toBe('runtime')
        ->and($routes[0]->fromNodeKind)->toBe('route');
});

it('skips closure routes (no controller to link)', function () {
    $events = new Dispatcher();
    $router = new Router($events);
    $router->get('api/ping', fn () => 'pong');

    expect(byKind(extractEdges($router, $events), 'route'))->toBeEmpty();
});

it('maps eloquent.* events to observer edges', function () {
    $events = new Dispatcher();
    $router = new Router($events);
    $events->listen('eloquent.saved: App\Models\User', 'App\Observers\UserObserver@saved');

    $observe = byKind(extractEdges($router, $events), 'observe');

    expect($observe)->toHaveCount(1)
        ->and($observe[0]->fromClass)->toBe('App\Models\User')
        ->and($observe[0]->fromMethod)->toBe('saved')
        ->and($observe[0]->fromNodeKind)->toBe('model-event')
        ->and($observe[0]->toClass)->toBe('App\Observers\UserObserver')
        ->and($observe[0]->toMethod)->toBe('saved');
});

it('maps a regular event to a listener edge', function () {
    $events = new Dispatcher();
    $router = new Router($events);
    $events->listen('App\Events\OrderPaid', 'App\Listeners\NotifyOps@handle');

    $eventEdges = byKind(extractEdges($router, $events), 'event');

    expect($eventEdges)->toHaveCount(1)
        ->and($eventEdges[0]->fromClass)->toBe('App\Events\OrderPaid')
        ->and($eventEdges[0]->toClass)->toBe('App\Listeners\NotifyOps')
        ->and($eventEdges[0]->toMethod)->toBe('handle');
});

it('treats an invokable listener as ::handle', function () {
    $events = new Dispatcher();
    $router = new Router($events);
    $events->listen('App\Events\OrderPaid', 'App\Listeners\InvokableListener');

    $eventEdges = byKind(extractEdges($router, $events), 'event');

    expect($eventEdges)->toHaveCount(1)
        ->and($eventEdges[0]->toClass)->toBe('App\Listeners\InvokableListener')
        ->and($eventEdges[0]->toMethod)->toBe('handle');
});

it('ignores closure listeners', function () {
    $events = new Dispatcher();
    $router = new Router($events);
    $events->listen('App\Events\OrderPaid', fn () => null);

    expect(byKind(extractEdges($router, $events), 'event'))->toBeEmpty();
});
