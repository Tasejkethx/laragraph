<?php

return [
    /*
     | Where the SQLite graph is written. Read back by the query commands.
     */
    'output' => env('LARAGRAPH_DB', base_path('laragraph.sqlite')),

    /*
     | Paths (relative to the app root) PHPStan analyses to build the static graph.
     */
    'analyse_paths' => ['app'],

    /*
     | Project PHPStan config to reuse, relative to the app root. Reusing the
     | project's phpstan.neon pulls in Larastan — without it Eloquent/facade
     | magic stays unresolved and the graph loses its Laravel-aware edges.
     | null = analyse with a bare PHPStan (weaker type resolution).
     */
    'phpstan_config' => 'phpstan.neon',
];
