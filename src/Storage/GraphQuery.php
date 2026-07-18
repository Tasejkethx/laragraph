<?php

declare(strict_types=1);

namespace Laragraph\Storage;

use PDO;

/**
 * Read-side of the graph: resolve a target to node ids, then walk edges.
 * Impact is a reverse BFS ("who transitively depends on this?").
 */
final class GraphQuery
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Match a target to node ids. Accepts a full FQN (`App\Foo::bar`), a bare
     * class (`App\Foo` → all its methods) or a short suffix (`Foo::bar`).
     *
     * @return list<int>
     */
    public function matchNodeIds(string $target): array
    {
        if (str_contains($target, '::')) {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM nodes WHERE fqn = :exact OR fqn LIKE '%\\' || :suffix"
            );
            $stmt->execute(['exact' => $target, 'suffix' => $target]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM nodes WHERE fqn = :exact OR fqn LIKE '%\\' || :cls || '::%' OR fqn LIKE :cls2 || '::%'"
            );
            $stmt->execute(['exact' => $target, 'cls' => $target, 'cls2' => $target]);
        }

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Direct callers of the given nodes (incoming edges).
     *
     * @param list<int> $nodeIds
     * @return list<array{fqn:string, kind:string, line:int}>
     */
    public function callers(array $nodeIds): array
    {
        return $this->neighbours($nodeIds, incoming: true);
    }

    /**
     * Direct callees of the given nodes (outgoing edges).
     *
     * @param list<int> $nodeIds
     * @return list<array{fqn:string, kind:string, line:int}>
     */
    public function callees(array $nodeIds): array
    {
        return $this->neighbours($nodeIds, incoming: false);
    }

    /**
     * Reverse-BFS blast radius: everything that transitively reaches the seed,
     * grouped by distance. This is the "what breaks if I touch X" query.
     *
     * @param list<int> $seedIds
     * @return list<array{fqn:string, depth:int}>
     */
    public function impact(array $seedIds, int $maxDepth): array
    {
        $visited = array_fill_keys($seedIds, true);
        $frontier = $seedIds;
        $result = [];

        for ($depth = 1; $depth <= $maxDepth && $frontier !== []; $depth++) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $this->pdo->prepare("SELECT DISTINCT from_id FROM edges WHERE to_id IN ({$placeholders})");
            $stmt->execute($frontier);

            $next = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $id = (int) $id;
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $next[] = $id;
            }

            foreach ($this->fqns($next) as $fqn) {
                $result[] = ['fqn' => $fqn, 'depth' => $depth];
            }

            $frontier = $next;
        }

        return $result;
    }

    /**
     * @param list<int> $nodeIds
     * @return list<array{fqn:string, kind:string, line:int}>
     */
    private function neighbours(array $nodeIds, bool $incoming): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $anchor = $incoming ? 'e.to_id' : 'e.from_id';
        $other = $incoming ? 'e.from_id' : 'e.to_id';
        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT n.fqn AS fqn, e.kind AS kind, e.line AS line
             FROM edges e JOIN nodes n ON n.id = {$other}
             WHERE {$anchor} IN ({$placeholders})
             ORDER BY n.fqn"
        );
        $stmt->execute($nodeIds);

        /** @var list<array{fqn:string, kind:string, line:int}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function fqns(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT fqn FROM nodes WHERE id IN ({$placeholders}) ORDER BY fqn");
        $stmt->execute($ids);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
