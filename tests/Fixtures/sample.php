<?php

declare(strict_types=1);

namespace Laragraph\Tests\Fixtures;

class Repo
{
    public function find(int $id): string
    {
        return (string) $id;
    }
}

class Service
{
    public function __construct(private Repo $repo)
    {
    }

    public function run(): string
    {
        // Expected edge: Service::run --call--> Repo::find
        return $this->repo->find(1);
    }
}
