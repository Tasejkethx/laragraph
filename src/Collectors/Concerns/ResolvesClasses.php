<?php

declare(strict_types=1);

namespace Laragraph\Collectors\Concerns;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

trait ResolvesClasses
{
    /**
     * Resolve the class(es) a static-call / new target refers to. A bare Name
     * (incl. self/static/parent, use-aliases, facades) resolves to an FQN; an
     * expression resolves through the type engine. Anonymous classes yield none.
     *
     * @return list<string>
     */
    private function resolveClasses(Node $classNode, Scope $scope): array
    {
        if ($classNode instanceof Name) {
            return [$scope->resolveName($classNode)];
        }

        if ($classNode instanceof Expr) {
            return $scope->getType($classNode)->getObjectClassNames();
        }

        return [];
    }
}
