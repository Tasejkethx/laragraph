<?php

declare(strict_types=1);

namespace Laragraph\Support;

/**
 * One directed edge of the call-graph: caller method → callee method.
 */
final class Edge
{
    public function __construct(
        public readonly string $fromClass,
        public readonly string $fromMethod,
        public readonly string $toClass,
        public readonly string $toMethod,
        public readonly int $line,
        public readonly string $kind,          // call | static | new | dispatch | event | route | observe | schedule | bind
        public readonly string $resolvedBy,    // phpstan | runtime
        public readonly string $fromNodeKind = 'method', // method | route | event | model-event
        public readonly string $toNodeKind = 'method',
    ) {
    }

    public function fromFqn(): string
    {
        return $this->fromClass.'::'.$this->fromMethod;
    }

    public function toFqn(): string
    {
        return $this->toClass.'::'.$this->toMethod;
    }
}
