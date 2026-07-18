<?php

declare(strict_types=1);

use Laragraph\Storage\SchemaManager;

/**
 * Fresh in-memory graph with the schema applied — the unit suite works on raw
 * PDO, no Laravel bootstrap needed.
 */
function freshGraph(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new SchemaManager($pdo))->migrate();

    return $pdo;
}
