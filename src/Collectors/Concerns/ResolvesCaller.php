<?php

declare(strict_types=1);

namespace Laragraph\Collectors\Concerns;

use PHPStan\Analyser\Scope;

trait ResolvesCaller
{
    /**
     * The [class, method] the current node is written in. Global functions get
     * a synthetic '{function}' class; anonymous closures and top-level code have
     * no stable caller identity and are skipped (null).
     *
     * @return array{string, string}|null
     */
    private function callerContext(Scope $scope): ?array
    {
        if ($scope->isInClass()) {
            $class = $scope->getClassReflection();

            return $class === null ? null : [$class->getName(), $scope->getFunctionName() ?? '{main}'];
        }

        $function = $scope->getFunctionName();

        return $function === null ? null : ['{function}', $function];
    }
}
