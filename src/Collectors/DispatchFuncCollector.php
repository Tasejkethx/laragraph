<?php

declare(strict_types=1);

namespace Laragraph\Collectors;

use Laragraph\Collectors\Concerns\ResolvesCaller;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * `dispatch(new SomeJob())` → an edge to `SomeJob::handle`, linking the caller
 * to the job's actual execution point rather than just its constructor.
 * Complements NewEdgeCollector (which sees the `new`, but points at __construct).
 *
 * @implements Collector<FuncCall, list<array{string, string, string, string, int, string}>>
 */
final class DispatchFuncCollector implements Collector
{
    use ResolvesCaller;

    private const DISPATCHERS = ['dispatch', 'dispatch_sync', 'dispatch_now'];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<array{string, string, string, string, int, string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->name instanceof Name) {
            return null;
        }
        if (! in_array(strtolower($node->name->toString()), self::DISPATCHERS, true)) {
            return null;
        }

        $args = $node->getArgs();
        if ($args === []) {
            return null;
        }

        $caller = $this->callerContext($scope);
        if ($caller === null) {
            return null;
        }
        [$fromClass, $fromMethod] = $caller;

        $classNames = $scope->getType($args[0]->value)->getObjectClassNames();
        if ($classNames === []) {
            return null;
        }

        $line = $node->getStartLine();
        $edges = [];
        foreach ($classNames as $toClass) {
            $edges[] = [$fromClass, $fromMethod, $toClass, 'handle', $line, 'dispatch'];
        }

        return $edges;
    }
}
