<?php

declare(strict_types=1);

namespace Laragraph\Collectors;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Collects instance method-call edges with the callee class resolved through
 * PHPStan's type engine — this is where Larastan's magic (facades, Eloquent
 * builder, generics) turns `$this->repo->find()` into a concrete class name.
 *
 * @implements Collector<MethodCall, list<array{string, string, string, string, int, string}>>
 */
final class CallEdgeCollector implements Collector
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<array{string, string, string, string, int, string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $scope->isInClass()) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier) {
            return null; // $obj->$dynamic() — out of scope for the static graph
        }

        $fromClass = $scope->getClassReflection()->getName();
        $fromMethod = $scope->getFunctionName() ?? '{main}';
        $toMethod = $node->name->toString();

        $classNames = $scope->getType($node->var)->getObjectClassNames();
        if ($classNames === []) {
            return null;
        }

        $line = $node->getStartLine();
        $edges = [];
        foreach ($classNames as $toClass) {
            $edges[] = [$fromClass, $fromMethod, $toClass, $toMethod, $line, 'call'];
        }

        return $edges;
    }
}
