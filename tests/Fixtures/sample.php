<?php

declare(strict_types=1);

namespace Laragraph\Tests\Fixtures;

class Repo
{
    public function find(int $id): string
    {
        return (string) $id;
    }

    public static function describe(): string
    {
        return 'repo';
    }
}

class Service
{
    public function __construct(private Repo $repo)
    {
    }

    public function run(): string
    {
        $fresh = new Repo();                    // new    → Repo::__construct

        return $this->repo->find(1)             // call   → Repo::find
            .$fresh->find(2)
            .Repo::describe();                  // static → Repo::describe
    }
}
