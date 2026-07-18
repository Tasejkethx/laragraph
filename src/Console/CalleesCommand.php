<?php

declare(strict_types=1);

namespace Laragraph\Console;

final class CalleesCommand extends GraphCommand
{
    protected $signature = 'graph:callees
        {target : FQN, Class::method, or short Class::method suffix}
        {--db= : Graph SQLite path (default: config laragraph.output)}
        {--json : Emit JSON instead of text}';

    protected $description = 'What the target directly calls (outgoing edges)';

    public function handle(): int
    {
        $graph = $this->graph();
        if ($graph === null) {
            return self::FAILURE;
        }

        $target = (string) $this->argument('target');
        $ids = $graph->matchNodeIds($target);
        if (! $this->ensureMatched($ids, $target)) {
            return self::SUCCESS;
        }

        $callees = $graph->callees($ids);

        if ($this->option('json')) {
            $this->emitJson($callees);

            return self::SUCCESS;
        }

        $this->info(count($callees)." callees of {$target}:");
        foreach ($callees as $callee) {
            $this->line("  {$callee['fqn']}  ({$callee['kind']}, line {$callee['line']})");
        }

        return self::SUCCESS;
    }
}
