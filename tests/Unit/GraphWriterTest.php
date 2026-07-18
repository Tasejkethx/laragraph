<?php

declare(strict_types=1);

use Laragraph\Storage\GraphWriter;
use Laragraph\Support\Edge;

it('inserts nodes and an edge', function () {
    $pdo = freshGraph();

    $inserted = (new GraphWriter($pdo))->write([
        new Edge('App\A', 'x', 'App\B', 'y', 3, 'call', 'phpstan'),
    ]);

    expect($inserted)->toBe(1)
        ->and((int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn())->toBe(2)
        ->and((int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn())->toBe(1);
});

it('dedupes identical edges across separate writes', function () {
    $pdo = freshGraph();
    $edge = new Edge('App\A', 'x', 'App\B', 'y', 3, 'call', 'phpstan');

    (new GraphWriter($pdo))->write([$edge]);
    $secondPass = (new GraphWriter($pdo))->write([$edge]);

    expect($secondPass)->toBe(0)
        ->and((int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn())->toBe(1);
});

it('keeps edges that differ only by line as distinct', function () {
    $pdo = freshGraph();

    (new GraphWriter($pdo))->write([
        new Edge('App\A', 'x', 'App\B', 'y', 3, 'call', 'phpstan'),
        new Edge('App\A', 'x', 'App\B', 'y', 9, 'call', 'phpstan'),
    ]);

    expect((int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn())->toBe(2);
});

it('stores the node kind for synthetic route nodes', function () {
    $pdo = freshGraph();

    (new GraphWriter($pdo))->write([
        new Edge('POST /foo', '@route', 'App\Ctrl', 'show', 0, 'route', 'runtime', 'route', 'method'),
    ]);

    $kind = $pdo->query("SELECT kind FROM nodes WHERE fqn = 'POST /foo::@route'")->fetchColumn();

    expect($kind)->toBe('route');
});
