<?php

return [
    /*
     | Where the SQLite graph is written. Read back by the query commands.
     */
    'output' => env('LARAGRAPH_DB', base_path('laragraph.sqlite')),

    /*
     | Paths (relative to the app root) PHPStan analyses to build the static graph.
     | Beyond app/, one-time operations, seeders/factories and route files also
     | call into services — leaving them out leaves holes in the blast-radius.
     | Missing directories are skipped, so this default is safe everywhere.
     */
    'analyse_paths' => ['app', 'database', 'routes', 'operations'],

    /*
     | PHPStan level for the graph run. Type resolution (facades, Eloquent) works
     | on every level — higher levels only sharpen generics at the cost of speed.
     */
    'level' => 5,

    /*
     | Larastan extension.neon files to include so Laravel magic (facade → class,
     | Eloquent builder, generics) resolves — this is what makes the graph
     | Laravel-aware. null = autodetect larastan/larastan or nunomaduro/larastan
     | in the app's vendor. We deliberately do NOT reuse the project's whole
     | phpstan.neon: its paths/baseline/disallowed rules would fight our run.
     */
    'larastan_includes' => null,
];
