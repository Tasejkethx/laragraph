<?php

declare(strict_types=1);

namespace Laragraph\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Routing\Router;
use Laragraph\Runtime\LaravelRuntimeExtractor;
use Laragraph\Storage\GraphWriter;
use Laragraph\Storage\SchemaManager;
use PDO;
use Symfony\Component\Process\Process;

final class BuildGraphCommand extends Command
{
    protected $signature = 'graph:build
        {--output= : SQLite graph path (default: config laragraph.output)}
        {--path=* : Paths to analyse, relative to app root (default: config laragraph.analyse_paths)}
        {--level= : PHPStan level for the run (default: config laragraph.level)}
        {--no-runtime : Skip the live-container pass (routes/events/observers)}';

    protected $description = 'Build a type-resolved Laravel call-graph via PHPStan';

    public function handle(): int
    {
        $output = (string) ($this->option('output') ?: config('laragraph.output'));
        $paths = $this->option('path') ?: config('laragraph.analyse_paths', ['app']);
        $paths = array_map(fn ($p) => base_path((string) $p), (array) $paths);
        $paths = array_values(array_filter($paths, 'is_dir'));

        if ($paths === []) {
            $this->error('None of the configured analyse paths exist.');

            return self::FAILURE;
        }

        $phpstan = base_path('vendor/bin/phpstan');
        if (! is_file($phpstan)) {
            $this->error("phpstan binary not found at {$phpstan}");

            return self::FAILURE;
        }

        $configPath = $this->writeRunConfig($output, $paths);

        $this->info('Analysing: '.implode(', ', $paths));
        $process = new Process(
            [$phpstan, 'analyse', '-c', $configPath, '--no-progress', '--error-format=json', '--memory-limit=2G'],
            base_path(),
            null,
            null,
            1800.0
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

        if (! $this->option('no-runtime')) {
            $this->appendRuntimeEdges($output);
        }

        $this->report($output);

        return self::SUCCESS;
    }

    /**
     * Second pass: edges Laravel only knows at runtime (routes/events/observers).
     * Appended to the static graph the PHPStan sink already wrote.
     */
    private function appendRuntimeEdges(string $output): void
    {
        $pdo = new PDO('sqlite:'.$output);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new SchemaManager($pdo))->migrate();

        $extractor = new LaravelRuntimeExtractor(
            $this->laravel->make(Router::class),
            $this->laravel->make(EventDispatcher::class),
        );

        $written = (new GraphWriter($pdo))->write($extractor->edges());
        $this->line("  runtime edges: +{$written}");
    }

    /**
     * @param list<string> $paths
     */
    private function writeRunConfig(string $output, array $paths): string
    {
        $includes = $this->larastanIncludes();
        $includes[] = dirname(__DIR__, 2).'/extension.neon';

        $neon = "includes:\n";
        foreach ($includes as $include) {
            $neon .= "\t- '{$include}'\n";
        }
        $level = $this->option('level');
        $level = $level === null ? (int) config('laragraph.level', 5) : (int) $level;

        $neon .= "parameters:\n";
        $neon .= "\tlevel: {$level}\n";
        $neon .= "\tlaragraph:\n\t\toutput: '{$output}'\n";
        $neon .= "\tpaths:\n";
        foreach ($paths as $path) {
            $neon .= "\t\t- '{$path}'\n";
        }

        // Written to the system temp dir, not the app's storage/, so a graph
        // run leaves no artifact in the target project's working tree.
        $configPath = sys_get_temp_dir().'/laragraph-run.neon';
        file_put_contents($configPath, $neon);

        return $configPath;
    }

    /**
     * @return list<string>
     */
    private function larastanIncludes(): array
    {
        $configured = config('laragraph.larastan_includes');
        if (is_array($configured)) {
            return array_values(array_filter($configured, 'is_file'));
        }

        foreach (['vendor/larastan/larastan/extension.neon', 'vendor/nunomaduro/larastan/extension.neon'] as $relative) {
            if (is_file(base_path($relative))) {
                return [base_path($relative)];
            }
        }

        return [];
    }

    private function report(string $output): void
    {
        $pdo = new PDO('sqlite:'.$output);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $nodes = (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
        $edges = (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn();

        /** @var array<string, int> $byResolver */
        $byResolver = $pdo->query('SELECT resolved_by, COUNT(*) FROM edges GROUP BY resolved_by')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->info("Graph built → {$output}");
        $this->line("  nodes: {$nodes}");
        $this->line("  edges: {$edges}");
        foreach ($byResolver as $resolver => $count) {
            $this->line("    {$resolver}: {$count}");
        }
    }
}
