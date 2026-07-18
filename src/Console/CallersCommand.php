<?php

declare(strict_types=1);

namespace Laragraph\Console;

final class CallersCommand extends GraphCommand
{
    protected $signature = 'graph:callers
        {target : FQN, Class::method, or short Class::method suffix}
        {--db= : Graph SQLite path (default: config laragraph.output)}
        {--json : Emit JSON instead of text}';

    protected $description = 'Who directly calls the target (incoming edges)';

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

        $callers = $graph->callers($ids);

        if ($this->option('json')) {
            $this->emitJson($callers);

            return self::SUCCESS;
        }

        $this->info(count($callers)." callers of {$target}:");
        foreach ($callers as $caller) {
            $this->line("  {$caller['fqn']}  ({$caller['kind']}, line {$caller['line']})");
        }

        return self::SUCCESS;
    }
}
