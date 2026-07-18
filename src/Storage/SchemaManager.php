<?php

declare(strict_types=1);

namespace Laragraph\Storage;

use PDO;

final class SchemaManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->pdo->exec('PRAGMA journal_mode=WAL');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS nodes (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                fqn   TEXT NOT NULL,
                kind  TEXT NOT NULL,
                file  TEXT,
                line  INTEGER,
                UNIQUE(fqn, kind)
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS edges (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                from_id     INTEGER NOT NULL,
                to_id       INTEGER NOT NULL,
                kind        TEXT NOT NULL,
                line        INTEGER,
                resolved_by TEXT NOT NULL,
                UNIQUE(from_id, to_id, kind, line),
                FOREIGN KEY(from_id) REFERENCES nodes(id),
                FOREIGN KEY(to_id)   REFERENCES nodes(id)
            )'
        );

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_edges_from ON edges(from_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_edges_to ON edges(to_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_nodes_fqn ON nodes(fqn)');
    }

    public function reset(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS edges');
        $this->pdo->exec('DROP TABLE IF EXISTS nodes');
        $this->migrate();
    }
}
