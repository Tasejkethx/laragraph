<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('retargets an eloquent scope call from Builder to the model', function () {
    $root = dirname(__DIR__, 2);
    $larastan = $root.'/vendor/nunomaduro/larastan/extension.neon';
    if (! is_file($larastan)) {
        $this->markTestSkipped('Larastan not installed — scope resolution needs it.');
    }

    $db = sys_get_temp_dir().'/laragraph-scope-'.getmypid().'.sqlite';
    $neonPath = sys_get_temp_dir().'/laragraph-scope-'.getmypid().'.neon';
    @unlink($db);

    $neon = "includes:\n"
        ."\t- '{$larastan}'\n"
        ."\t- '{$root}/extension.neon'\n"
        ."parameters:\n"
        ."\tlevel: 5\n"
        ."\tlaragraph:\n\t\toutput: '{$db}'\n"
        ."\tpaths:\n\t\t- '{$root}/tests/Fixtures/model.php'\n";
    file_put_contents($neonPath, $neon);

    (new Process([$root.'/vendor/bin/phpstan', 'analyse', '-c', $neonPath, '--no-progress'], $root))->run();

    expect(is_file($db))->toBeTrue('phpstan sink did not produce the graph DB');

    $pdo = new PDO('sqlite:'.$db);
    $pairs = array_map(
        fn ($r) => $r['f'].' -> '.$r['t'].' ('.$r['k'].')',
        $pdo->query(
            'SELECT nf.fqn AS f, nt.fqn AS t, e.kind AS k
             FROM edges e
             JOIN nodes nf ON nf.id = e.from_id
             JOIN nodes nt ON nt.id = e.to_id'
        )->fetchAll(PDO::FETCH_ASSOC)
    );

    expect($pairs)->toContain(
        'Laragraph\Tests\Fixtures\WidgetRepo::activeWidgets -> Laragraph\Tests\Fixtures\Widget::scopeActive (scope)'
    );

    @unlink($db);
    @unlink($neonPath);
});
