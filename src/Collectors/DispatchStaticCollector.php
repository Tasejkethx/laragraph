<?php

declare(strict_types=1);

namespace Laragraph\Collectors;

use Laragraph\Collectors\Concerns\ResolvesCaller;
use Laragraph\Collectors\Concerns\ResolvesClasses;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Static job dispatch: `SomeJob::dispatch(...)` (the Dispatchable trait). Emits
 * two edges: → `SomeJob::handle` (the execution point) and → `SomeJob::__construct`
 * — because Dispatchable does `new static(...$args)` internally, so the ctor call
 * is invisible to NewEdgeCollector. Without the ctor edge, `callers ::__construct`
 * would miss every dispatch site. DispatchFuncCollector covers `dispatch(new Job)`
 * (there the `new` is explicit, so NewEdgeCollector already links the ctor).
 *
 * The `handle` check keeps it honest — a `Bus::dispatch()` or any unrelated
 * static `dispatch` on a class without a handler produces no edge.
 *
 * @implements Collector<StaticCall, list<array{string, string, string, string, int, string}>>
 */
final class DispatchStaticCollector implements Collector
{
    use ResolvesCaller;
    use ResolvesClasses;

    private const DISPATCHERS = ['dispatch', 'dispatchSync', 'dispatchAfterResponse', 'dispatchNow'];

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

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
        if (! in_array($node->name->toString(), self::DISPATCHERS, true)) {
            return null;
        }

        $caller = $this->callerContext($scope);
        if ($caller === null) {
            return null;
        }
        [$fromClass, $fromMethod] = $caller;

        $line = $node->getStartLine();
        $edges = [];
        foreach ($this->resolveClasses($node->class, $scope) as $toClass) {
            if ($this->dispatchesToHandle($toClass)) {
                $edges[] = [$fromClass, $fromMethod, $toClass, 'handle', $line, 'dispatch'];
                $edges[] = [$fromClass, $fromMethod, $toClass, '__construct', $line, 'dispatch'];
            }
        }

        return $edges === [] ? null : $edges;
    }

    private function dispatchesToHandle(string $class): bool
    {
        return $this->reflectionProvider->hasClass($class)
            && $this->reflectionProvider->getClass($class)->hasMethod('handle');
    }
}
