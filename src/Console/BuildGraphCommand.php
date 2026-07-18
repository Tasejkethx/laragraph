<?php

declare(strict_types=1);

namespace Laragraph\Console;

use Illuminate\Console\Command;
use PDO;
use Symfony\Component\Process\Process;

final class BuildGraphCommand extends Command
{
    protected $signature = 'graph:build
        {--output= : SQLite graph path (default: config laragraph.output)}
        {--path=* : Paths to analyse, relative to app root (default: config laragraph.analyse_paths)}';

    protected $description = 'Build a type-resolved Laravel call-graph via PHPStan';

    public function handle(): int
    {
        $output = (string) ($this->option('output') ?: config('laragraph.output'));
        $paths = $this->option('path') ?: config('laragraph.analyse_paths', ['app']);
        $paths = array_map(fn ($p) => base_path((string) $p), (array) $paths);

        $phpstan = base_path('vendor/bin/phpstan');
        if (! is_file($phpstan)) {
            $this->error("phpstan binary not found at {$phpstan}");

            return self::FAILURE;
        }

        $configPath = $this->writeRunConfig($output, $paths);

        $this->info('Analysing: '.implode(', ', $paths));
        $process = new Process(
            [$phpstan, 'analyse', '-c', $configPath, '--no-progress', '--error-format=json'],
            base_path(),
            null,
            null,
            600.0
        );
        $process->run();

        // PHPStan exits non-zero when the reused config surfaces real analysis
        // errors — that's expected and irrelevant here. The sink rule reports
        // none; the only success signal we care about is that the DB was built.
        if (! is_file($output)) {
            $this->error('Graph DB was not produced. PHPStan said:');
            $this->line($process->getErrorOutput() ?: $process->getOutput());

            return self::FAILURE;
        }

        $this->report($output);

        return self::SUCCESS;
    }

    /**
     * @param list<string> $paths
     */
    private function writeRunConfig(string $output, array $paths): string
    {
        $includes = [dirname(__DIR__, 2).'/extension.neon'];

        $projectConfig = config('laragraph.phpstan_config');
        if (is_string($projectConfig) && is_file(base_path($projectConfig))) {
            array_unshift($includes, base_path($projectConfig));
        }

        $neon = "includes:\n";
        foreach ($includes as $include) {
            $neon .= "\t- '{$include}'\n";
        }
        $neon .= "parameters:\n";
        $neon .= "\tlaragraph:\n\t\toutput: '{$output}'\n";
        $neon .= "\tpaths:\n";
        foreach ($paths as $path) {
            $neon .= "\t\t- '{$path}'\n";
        }

        $configPath = storage_path('laragraph-run.neon');
        file_put_contents($configPath, $neon);

        return $configPath;
    }

    private function report(string $output): void
    {
        $pdo = new PDO('sqlite:'.$output);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $nodes = (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
        $edges = (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn();

        $this->info("Graph built → {$output}");
        $this->line("  nodes: {$nodes}");
        $this->line("  edges: {$edges}");
    }
}
