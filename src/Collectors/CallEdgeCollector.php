<?php

declare(strict_types=1);

namespace Laragraph\Collectors;

use Laragraph\Collectors\Concerns\ResolvesCaller;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * Collects instance method-call edges with the callee class resolved through
 * PHPStan's type engine — this is where Larastan's magic (facades, Eloquent
 * builder, generics) turns `$this->repo->find()` into a concrete class name.
 *
 * Eloquent scopes get special treatment: a call on `Builder<PaymentRequest>`
 * whose method matches a `scopeX` on the model is retargeted from the generic
 * `Builder::x` to `PaymentRequest::scopeX`, so impact points at the real code.
 *
 * @implements Collector<MethodCall, list<array{string, string, string, string, int, string}>>
 */
final class CallEdgeCollector implements Collector
{
    use ResolvesCaller;

    private const ELOQUENT_BUILDER = 'Illuminate\Database\Eloquent\Builder';
    private const ELOQUENT_RELATION = 'Illuminate\Database\Eloquent\Relations\\';

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<array{string, string, string, string, int, string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node->name instanceof Node\Identifier) {
            return null; // $obj->$dynamic() — out of scope for the static graph
        }

        $caller = $this->callerContext($scope);
        if ($caller === null) {
            return null;
        }
        [$fromClass, $fromMethod] = $caller;
        $toMethod = $node->name->toString();

        $receiver = $scope->getType($node->var);
        $classNames = $receiver->getObjectClassNames();
        if ($classNames === []) {
            return null;
        }

        $line = $node->getStartLine();

        $model = $this->scopeOwner($receiver, $toMethod);
        if ($model !== null) {
            return [[$fromClass, $fromMethod, $model, 'scope'.ucfirst($toMethod), $line, 'scope']];
        }

        $edges = [];
        foreach ($classNames as $toClass) {
            $edges[] = [$fromClass, $fromMethod, $toClass, $toMethod, $line, 'call'];
        }

        return $edges;
    }

    /**
     * If the receiver is a `Builder<Model>` (or relation) and the method is a
     * local scope on that model, return the model FQN; otherwise null.
     */
    private function scopeOwner(Type $receiver, string $method): ?string
    {
        $model = $this->eloquentModelBehind($receiver);
        if ($model === null || ! $this->reflectionProvider->hasClass($model)) {
            return null;
        }

        return $this->reflectionProvider->getClass($model)->hasMethod('scope'.ucfirst($method))
            ? $model
            : null;
    }

    private function eloquentModelBehind(Type $receiver): ?string
    {
        $candidates = $receiver instanceof UnionType ? $receiver->getTypes() : [$receiver];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof GenericObjectType) {
                continue;
            }
            if (! $this->isEloquentBuilder($candidate->getClassName())) {
                continue;
            }

            $typeArguments = $candidate->getTypes();
            $modelNames = $typeArguments === [] ? [] : $typeArguments[0]->getObjectClassNames();
            if ($modelNames !== []) {
                return $modelNames[0];
            }
        }

        return null;
    }

    private function isEloquentBuilder(string $class): bool
    {
        if ($class === self::ELOQUENT_BUILDER || str_starts_with($class, self::ELOQUENT_RELATION)) {
            return true;
        }

        return $this->reflectionProvider->hasClass($class)
            && $this->reflectionProvider->getClass($class)->isSubclassOf(self::ELOQUENT_BUILDER);
    }
}
