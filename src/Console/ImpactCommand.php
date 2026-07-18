<?php

declare(strict_types=1);

namespace Laragraph\Console;

final class ImpactCommand extends GraphCommand
{
    protected $signature = 'graph:impact
        {target : FQN, Class::method, or short Class::method suffix}
        {--depth=3 : Max reverse-BFS distance}
        {--db= : Graph SQLite path (default: config laragraph.output)}
        {--json : Emit JSON instead of text}';

    protected $description = 'Blast radius: everything that transitively depends on the target';

    public function handle(): int
    {
        $graph = $this->graph();
        if ($graph === null) {
            return self::FAILURE;
        }

        $target = (string) $this->argument('target');
        $depth = max(1, (int) $this->option('depth'));

        $ids = $graph->matchNodeIds($target);
        if (! $this->ensureMatched($ids, $target)) {
            return self::SUCCESS;
        }

        $radius = $graph->impact($ids, $depth);

        if ($this->option('json')) {
            $this->emitJson($radius);

            return self::SUCCESS;
        }

        $this->info(count($radius)." nodes depend on {$target} (depth ≤ {$depth}):");

        $current = 0;
        foreach ($radius as $node) {
            if ($node['depth'] !== $current) {
                $current = $node['depth'];
                $this->line("  [depth {$current}]");
            }
            $this->line("    {$node['fqn']}");
        }

        return self::SUCCESS;
    }
}
