# laragraph

Type-resolved **call-graph for Laravel**, built on top of PHPStan / Larastan.

Syntax-based tools (CodeGraph, graphify, deptrac, tree-sitter indexers) read the
graph off the *text* of your code. In Laravel that leaves them blind to most of
the wiring. laragraph reads the graph off PHPStan's **resolved types** and off the
**live container**, so it sees edges the others structurally cannot:

- **facade → concrete class** — `Cache::get()` points at the real store
- **Eloquent magic** — `User::query()->whereEmail()` resolves to the builder, and
  a local scope call (`->byUser()`) is retargeted to `User::scopeByUser`
- **job dispatch** — both `dispatch(new Job)` and `SomeJob::dispatch()` link to
  `Job::handle` (the execution point)
- **dynamic wiring** — routes → controllers, events → listeners, `Model::observe()`

The graph is stored in SQLite and queried for **impact maps** — callers, callees,
and blast-radius — primarily as context for an LLM coding agent, but useful for
any "what depends on this?" question.

## Install

Into a Laravel 9–11 app. The package is a dev-time analysis tool.

```jsonc
// composer.json of the target app
"repositories": [
    { "type": "vcs", "url": "https://github.com/Tasejkethx/laragraph" }
],
"require-dev": {
    "laragraph/laragraph": "^1.0"
}
```

```bash
composer update laragraph/laragraph
```

The service provider is auto-discovered. Larastan is auto-detected from the app's
vendor — **without it, Eloquent/facade magic stays unresolved**, so keep
`larastan/larastan` (or `nunomaduro/larastan`) installed.

<details>
<summary>Local development against a checkout</summary>

```jsonc
"repositories": [{ "type": "path", "url": "../laragraph" }],
"require-dev": { "laragraph/laragraph": "*" }
```
</details>

## Usage

Build the graph, then query it:

```bash
php artisan graph:build                          # analyse the whole app
php artisan graph:build --path=app/Services       # a subset (faster, focused)
php artisan graph:build --path=app/Jobs --no-runtime --level=1

php artisan graph:callers 'App\Services\OrderService::place'
php artisan graph:callees 'App\Services\OrderService::place'
php artisan graph:impact  'App\Services\OrderService::place' --depth=4
```

The target accepts a full FQN, `Class::method`, or a short `Class::method` suffix.
`--json` emits machine-readable output; `--db=` selects a graph file. `impact` is a
reverse BFS — everything that transitively depends on the target.

```
$ php artisan graph:callers 'App\Services\OrderService::place'
2 callers of App\Services\OrderService::place:
  App\Http\Controllers\OrderController::store  (call, line 34)
  App\Console\Commands\ReplayOrders::handle    (call, line 51)
```

> **Note.** `callers` and `impact` need the *whole* graph — a narrow `--path`
> can't see callers that live outside it. Use `--path` for `callees` (outgoing,
> self-contained) and for quick focused rebuilds; use a full build before asking
> "who depends on this?".

## How it works

`graph:build` runs two passes and writes both into one SQLite file:

```
php artisan graph:build
   │
   ├─ PHPStan analyser (laragraph extension) — static, resolved_by=phpstan
   │     CallEdgeCollector        →  $x->method()        (receiver resolved via Scope;
   │                                                       Eloquent scope → Model::scopeX)
   │     StaticCallEdgeCollector  →  Foo::bar(), self::, facades, Model::create()
   │     NewEdgeCollector         →  new Foo()           → Foo::__construct
   │     DispatchFuncCollector    →  dispatch(new Job)   → Job::handle
   │     DispatchStaticCollector  →  SomeJob::dispatch() → Job::handle + Job::__construct
   │        ↓ CollectedDataNode (one virtual node after full analysis)
   │     GraphSinkRule → writes nodes + edges to SQLite
   │
   └─ live Laravel container — dynamic, resolved_by=runtime
         routes → controller::method,  event → listener,  model → observer
```

Calls made from global functions (helpers) are kept under a synthetic
`{function}` node. Closures and top-level code have no stable identity and are
skipped.

`resolved_by` records whether an edge came from static analysis (`phpstan`) or the
live framework (`runtime`) — so the dynamic wiring invisible to every syntax-based
tool is explicitly accounted for.

## Graph schema

```sql
nodes(id, fqn, kind, file, line)
   -- kind: method | route | event | model-event

edges(from_id, to_id, kind, line, resolved_by)
   -- kind:        call | static | new | scope | dispatch | route | event | observe
   -- resolved_by: phpstan | runtime
```

Query it directly if the commands aren't enough:

```bash
sqlite3 laragraph.sqlite \
  "SELECT nf.fqn, nt.fqn, e.kind FROM edges e
   JOIN nodes nf ON nf.id = e.from_id
   JOIN nodes nt ON nt.id = e.to_id
   WHERE e.resolved_by = 'runtime'"
```

## Configuration

`php artisan vendor:publish --tag=laragraph-config` → `config/laragraph.php`:

| Key | Default | Purpose |
|---|---|---|
| `output` | `base_path('laragraph.sqlite')` | where the graph is written (env `LARAGRAPH_DB`) |
| `analyse_paths` | `['app', 'database', 'routes', 'operations']` | paths to analyse; missing ones are skipped |
| `level` | `5` | PHPStan level (type resolution works on any level) |
| `larastan_includes` | `null` (autodetect) | Larastan extension `.neon`s to include |

CLI flags override config: `--output`, `--path=*`, `--level`, `--no-runtime`.

Add the output file to your app's `.gitignore` (e.g. `laragraph.sqlite`).

## Performance

PHPStan's result cache makes rebuilds incremental, in an **isolated** cache dir so
it never fights your project's own `phpstan analyse`. Measured on a ~2900-file app
(~49k edges):

| | Time |
|---|---|
| First build (cold) | ~8–9 min, **once** |
| Rebuild, nothing changed (warm) | **~4 s** |
| Rebuild after editing a file | **~6 s** |
| Focused `--path=app/SomeModule` | **~6–7 s** |

A full cold build recurs only on the first run, or when the package / Larastan /
composer deps change (the whole cache is invalidated), or on a mass edit. Ordinary
edits recompute only the touched files plus their type-dependents. The runtime pass
(routes/events/observers) is not cached — skip it with `--no-runtime`.

There is no file watcher: rebuild by running `graph:build` (which is cheap after
the first build).

## Compatibility

**PHPStan `^1.12`**, Laravel **9–11**, PHP **>= 8.2**. PHPStan 2 is not yet
supported — its `Collector` generics and `RuleError` contract changed and need
verification before widening the constraint.

## Development

```bash
composer install
vendor/bin/pest      # 17 tests: unit (query/writer/runtime) + phpstan integration
```

## License

MIT.
