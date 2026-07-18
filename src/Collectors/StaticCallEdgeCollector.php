<?php

declare(strict_types=1);

namespace Laragraph\Collectors;

use Laragraph\Collectors\Concerns\ResolvesCaller;
use Laragraph\Collectors\Concerns\ResolvesClasses;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Static calls: `Foo::bar()`, `self::x()`, `Model::create()`, facade calls.
 * The class is resolved to an FQN (facades stay facades — resolving them to the
 * concrete class behind ::getFacadeAccessor is a later refinement).
 *
 * @implements Collector<StaticCall, list<array{string, string, string, string, int, string}>>
 */
final class StaticCallEdgeCollector implements Collector
{
    use ResolvesCaller;
    use ResolvesClasses;

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<array{string, string, string, string, int, string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }

        $caller = $this->callerContext($scope);
        if ($caller === null) {
            return null;
        }
        [$fromClass, $fromMethod] = $caller;
        $toMethod = $node->name->toString();

        $classNames = $this->resolveClasses($node->class, $scope);
        if ($classNames === []) {
            return null;
        }

        $line = $node->getStartLine();
        $edges = [];
        foreach ($classNames as $toClass) {
            $edges[] = [$fromClass, $fromMethod, $toClass, $toMethod, $line, 'static'];
        }

        return $edges;
    }
}
