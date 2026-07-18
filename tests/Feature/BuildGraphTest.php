<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('builds a type-resolved static edge from the fixture via phpstan', function () {
    $root = dirname(__DIR__, 2);
    $db = sys_get_temp_dir().'/laragraph-feature-'.getmypid().'.sqlite';
    $neonPath = sys_get_temp_dir().'/laragraph-feature-'.getmypid().'.neon';
    @unlink($db);

    $neon = "includes:\n"
        ."\t- '{$root}/extension.neon'\n"
        ."parameters:\n"
        ."\tlevel: 5\n"
        ."\tlaragraph:\n\t\toutput: '{$db}'\n"
        ."\tpaths:\n\t\t- '{$root}/tests/Fixtures'\n";
    file_put_contents($neonPath, $neon);

    $process = new Process(
        [$root.'/vendor/bin/phpstan', 'analyse', '-c', $neonPath, '--no-progress'],
        $root
    );
    $process->run();

    expect(is_file($db))->toBeTrue('phpstan sink did not produce the graph DB');

    $pdo = new PDO('sqlite:'.$db);
    $rows = $pdo->query(
        'SELECT nf.fqn AS f, nt.fqn AS t
         FROM edges e
         JOIN nodes nf ON nf.id = e.from_id
         JOIN nodes nt ON nt.id = e.to_id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $pairs = array_map(fn ($r) => $r['f'].' -> '.$r['t'], $rows);
    $run = 'Laragraph\Tests\Fixtures\Service::run -> Laragraph\Tests\Fixtures\Repo::';

    expect($pairs)
        ->toContain($run.'find')          // MethodCall (type-resolved receiver)
        ->toContain($run.'describe')      // StaticCall
        ->toContain($run.'__construct');  // New_

    @unlink($db);
    @unlink($neonPath);
});
