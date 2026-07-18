<?php

declare(strict_types=1);

namespace Laragraph\Collectors;

use Laragraph\Collectors\Concerns\ResolvesClasses;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Object construction: `new Service()` → an edge to `Service::__construct`.
 * Surfaces constructor/DI wiring the call-graph would otherwise miss.
 *
 * @implements Collector<New_, list<array{string, string, string, string, int, string}>>
 */
final class NewEdgeCollector implements Collector
{
    use ResolvesClasses;

    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @return list<array{string, string, string, string, int, string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $scope->isInClass()) {
            return null;
        }

        $fromClass = $scope->getClassReflection()->getName();
        $fromMethod = $scope->getFunctionName() ?? '{main}';

        $classNames = $this->resolveClasses($node->class, $scope);
        if ($classNames === []) {
            return null; // anonymous class or unresolved expression
        }

        $line = $node->getStartLine();
        $edges = [];
        foreach ($classNames as $toClass) {
            $edges[] = [$fromClass, $fromMethod, $toClass, '__construct', $line, 'new'];
        }

        return $edges;
    }
}
