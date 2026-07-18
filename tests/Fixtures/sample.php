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

class Job
{
    public function handle(): void
    {
    }
}

class QueuedJob
{
    public function handle(): void
    {
    }
}

class Service
{
    public function __construct(private Repo $repo)
    {
    }

    public function run(): string
    {
        $fresh = new Repo();                    // new      → Repo::__construct
        dispatch(new Job());                    // dispatch → Job::handle   (helper form)
        QueuedJob::dispatch();                  // dispatch → QueuedJob::handle (static form)

        return $this->repo->find(1)             // call     → Repo::find
            .$fresh->find(2)
            .Repo::describe();                  // static   → Repo::describe
    }
}

function helper_run(Repo $repo): string
{
    return $repo->find(5);                      // {function}::helper_run → Repo::find
}
