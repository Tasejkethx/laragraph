<?php

declare(strict_types=1);

namespace Laragraph\Console;

use Illuminate\Console\Command;
use Laragraph\Storage\GraphQuery;
use PDO;

abstract class GraphCommand extends Command
{
    protected function graph(): ?GraphQuery
    {
        $db = (string) ($this->option('db') ?: config('laragraph.output'));

        if (! is_file($db)) {
            $this->error("Graph DB not found at {$db} — run `php artisan graph:build` first.");

            return null;
        }

        $pdo = new PDO('sqlite:'.$db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new GraphQuery($pdo);
    }

    /**
     * @param list<int> $ids
     */
    protected function ensureMatched(array $ids, string $target): bool
    {
        if ($ids === []) {
            $this->warn("No node matches '{$target}'. Check the FQN or rebuild the graph.");

            return false;
        }

        return true;
    }

    protected function emitJson(mixed $payload): void
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
