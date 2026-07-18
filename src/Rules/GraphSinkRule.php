<?php

declare(strict_types=1);

namespace Laragraph\Rules;

use Laragraph\Collectors\CallEdgeCollector;
use Laragraph\Collectors\DispatchFuncCollector;
use Laragraph\Collectors\NewEdgeCollector;
use Laragraph\Collectors\StaticCallEdgeCollector;
use Laragraph\Storage\GraphWriter;
use Laragraph\Storage\SchemaManager;
use Laragraph\Support\Edge;
use PDO;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;

/**
 * Not a real "rule" — it never reports errors. It hooks the single virtual
 * CollectedDataNode that PHPStan emits after the whole project is analysed,
 * drains every collector's data and writes the graph to SQLite as a side
 * effect. Runs once, in the main process, after parallel workers are merged.
 *
 * @implements Rule<CollectedDataNode>
 */
final class GraphSinkRule implements Rule
{
    public function __construct(private readonly string $outputPath)
    {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $pdo = new PDO('sqlite:'.$this->outputPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new SchemaManager($pdo))->reset();

        (new GraphWriter($pdo))->write($this->edges($node));

        return [];
    }

    /**
     * @return iterable<Edge>
     */
    private function edges(CollectedDataNode $node): iterable
    {
        $collectors = [
            CallEdgeCollector::class,
            StaticCallEdgeCollector::class,
            NewEdgeCollector::class,
            DispatchFuncCollector::class,
        ];

        foreach ($collectors as $collector) {
            foreach ($node->get($collector) as $perFileReturns) {
                foreach ($perFileReturns as $perCallSite) {
                    foreach ($perCallSite as $row) {
                        [$fromClass, $fromMethod, $toClass, $toMethod, $line, $kind] = $row;
                        yield new Edge($fromClass, $fromMethod, $toClass, $toMethod, $line, $kind, 'phpstan');
                    }
                }
            }
        }
    }
}
