<?php

declare(strict_types=1);

namespace Laragraph\Storage;

use Laragraph\Support\Edge;
use PDO;
use PDOStatement;

final class GraphWriter
{
    /** @var array<string, int> fqn+kind → node id */
    private array $nodeCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param iterable<Edge> $edges
     * @return int number of edge rows actually inserted (duplicates ignored)
     */
    public function write(iterable $edges): int
    {
        $insertNode = $this->pdo->prepare('INSERT OR IGNORE INTO nodes (fqn, kind) VALUES (:fqn, :kind)');
        $selectNode = $this->pdo->prepare('SELECT id FROM nodes WHERE fqn = :fqn AND kind = :kind');
        $insertEdge = $this->pdo->prepare(
            'INSERT OR IGNORE INTO edges (from_id, to_id, kind, line, resolved_by)
             VALUES (:from_id, :to_id, :kind, :line, :resolved_by)'
        );

        $inserted = 0;
        $this->pdo->beginTransaction();

        foreach ($edges as $edge) {
            $fromId = $this->nodeId($insertNode, $selectNode, $edge->fromFqn(), $edge->fromNodeKind);
            $toId = $this->nodeId($insertNode, $selectNode, $edge->toFqn(), $edge->toNodeKind);

            $insertEdge->execute([
                'from_id' => $fromId,
                'to_id' => $toId,
                'kind' => $edge->kind,
                'line' => $edge->line,
                'resolved_by' => $edge->resolvedBy,
            ]);
            $inserted += $insertEdge->rowCount();
        }

        $this->pdo->commit();

        return $inserted;
    }

    private function nodeId(PDOStatement $insert, PDOStatement $select, string $fqn, string $kind = 'method'): int
    {
        $cacheKey = $kind.' '.$fqn;
        if (isset($this->nodeCache[$cacheKey])) {
            return $this->nodeCache[$cacheKey];
        }

        $insert->execute(['fqn' => $fqn, 'kind' => $kind]);
        $select->execute(['fqn' => $fqn, 'kind' => $kind]);

        return $this->nodeCache[$cacheKey] = (int) $select->fetchColumn();
    }
}
