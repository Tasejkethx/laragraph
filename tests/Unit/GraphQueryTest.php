<?php

declare(strict_types=1);

use Laragraph\Storage\GraphQuery;
use Laragraph\Storage\GraphWriter;
use Laragraph\Support\Edge;

function seed(PDO $pdo, Edge ...$edges): void
{
    (new GraphWriter($pdo))->write($edges);
}

it('resolves callers and callees of a method', function () {
    $pdo = freshGraph();
    seed(
        $pdo,
        new Edge('App\A', 'run', 'App\B', 'find', 10, 'call', 'phpstan'),
        new Edge('App\C', 'handle', 'App\A', 'run', 5, 'call', 'phpstan'),
    );
    $query = new GraphQuery($pdo);
    $ids = $query->matchNodeIds('App\A::run');

    $callees = $query->callees($ids);
    $callers = $query->callers($ids);

    expect($callees)->toHaveCount(1)
        ->and($callees[0]['fqn'])->toBe('App\B::find')
        ->and($callers)->toHaveCount(1)
        ->and($callers[0]['fqn'])->toBe('App\C::handle');
});

it('matches every method of a bare class', function () {
    $pdo = freshGraph();
    seed(
        $pdo,
        new Edge('App\A', 'one', 'App\X', 'a', 1, 'call', 'phpstan'),
        new Edge('App\A', 'two', 'App\Y', 'b', 2, 'call', 'phpstan'),
    );
    $ids = (new GraphQuery($pdo))->matchNodeIds('App\A');

    // App\A::one and App\A::two both match; their callees are X::a and Y::b.
    expect($ids)->toHaveCount(2);
});

it('walks the reverse blast radius by depth', function () {
    $pdo = freshGraph();
    seed(
        $pdo,
        new Edge('App\B', 'm', 'App\A', 'target', 1, 'call', 'phpstan'), // B → A
        new Edge('App\C', 'm', 'App\B', 'm', 1, 'call', 'phpstan'),      // C → B
        new Edge('App\D', 'm', 'App\C', 'm', 1, 'call', 'phpstan'),      // D → C
    );
    $query = new GraphQuery($pdo);
    $seed = $query->matchNodeIds('App\A::target');

    expect(array_column($query->impact($seed, 1), 'fqn'))
        ->toBe(['App\B::m']);

    expect(array_column($query->impact($seed, 2), 'fqn'))
        ->toEqualCanonicalizing(['App\B::m', 'App\C::m']);

    expect($query->impact($seed, 5))->toHaveCount(3);
});

it('does not revisit nodes in a cycle', function () {
    $pdo = freshGraph();
    seed(
        $pdo,
        new Edge('App\A', 'm', 'App\B', 'm', 1, 'call', 'phpstan'),
        new Edge('App\B', 'm', 'App\A', 'm', 1, 'call', 'phpstan'), // cycle A↔B
    );
    $query = new GraphQuery($pdo);
    $seed = $query->matchNodeIds('App\A::m');

    // B reaches A; A must not reappear (it's the seed) — radius is just {B}.
    expect($query->impact($seed, 10))->toHaveCount(1);
});

it('returns nothing for an unknown target', function () {
    $query = new GraphQuery(freshGraph());

    expect($query->matchNodeIds('App\Nope::gone'))->toBe([])
        ->and($query->callers([]))->toBe([])
        ->and($query->callees([]))->toBe([]);
});
