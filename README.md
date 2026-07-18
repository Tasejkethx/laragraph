# laragraph

Type-resolved **call-graph for Laravel**, built on top of PHPStan/Larastan.

Unlike syntax-based tools (CodeGraph, graphify, deptrac, tree-sitter indexers),
laragraph reads the graph off PHPStan's **resolved types**. That means it sees
edges the others structurally cannot:

- facade → concrete class (`Cache::get()` → the real store)
- Eloquent magic — `User::query()->whereEmail()` resolves to the builder, and a
  local scope call (`->byUser()`) is retargeted to `User::scopeByUser`
- generics on collections
- and — via a live-container pass — Laravel's dynamic wiring: routes → controllers,
  events → listeners, `Model::observe()`.

The graph is stored in SQLite and queried for **impact maps** (callers / callees /
blast-radius), primarily as context for an LLM coding agent.

## Status

Working MVP. Scales to multi-thousand-file applications (a full build takes minutes).

| Phase | | |
|---|---|---|
| Ф0 skeleton | ✅ | package, provider, PHPStan extension, SQLite schema |
| Ф1 static core | ✅ | method / static / `new` calls, type-resolved; Eloquent scope→model |
| Ф2 Laravel dynamics | ✅ | routes, events, observers (live container) |
| Ф3 agent queries | ✅ | `graph:callers` / `graph:callees` / `graph:impact` |

Backlog: `schedule` edges, facade→concrete-class for static calls, PHPStan 2 support
(see Compatibility), publishing to a git remote.

## How it works

```
php artisan graph:build
   │
   ├─ PHPStan analyser (laragraph extension) — static, resolved_by=phpstan
   │     CallEdgeCollector        →  $x->method()      (callee resolved via Scope)
   │     StaticCallEdgeCollector  →  Foo::bar()
   │     NewEdgeCollector         →  new Foo()         → Foo::__construct
   │     DispatchFuncCollector    →  dispatch(new Job) → Job::handle
   │        ↓ CollectedDataNode (one virtual node after full analysis)
   │     GraphSinkRule → writes nodes + edges to SQLite
   │
   └─ live Laravel container — dynamic, resolved_by=runtime
         routes → controller::method,  event → listener,  model → observer
```

`edges.resolved_by` records whether an edge came from static analysis (`phpstan`)
or the live framework (`runtime`) — so the Laravel dynamics invisible to every
syntax-based tool are explicitly accounted for. `edges.kind` is one of
`call | static | new | scope | dispatch | route | event | observe`.

## Query the graph (agent-facing)

```bash
php artisan graph:callers 'App\Services\OrderService::place'
php artisan graph:callees 'App\Services\OrderService::place'
php artisan graph:impact  'App\Services\OrderService::place' --depth=4
```

Target accepts a full FQN, `Class::method`, or a short `Class::method` suffix.
`--json` for machine output, `--db=` to pick a graph file. `impact` is a reverse
BFS — "what transitively depends on this".

## Install (into a Laravel app, local path repo)

```jsonc
// composer.json of the target app
"repositories": [
    { "type": "path", "url": "../laragraph" }
],
"require-dev": { "laragraph/laragraph": "*" }
```

Larastan is auto-detected from the app's vendor (config `laragraph.larastan_includes`
to override) — without it, Eloquent/facade magic stays unresolved. Then:

```bash
php artisan graph:build              # whole app/, level from config
php artisan graph:build --path=app/Services --level=1 --no-runtime
```

## Compatibility

Targets **PHPStan ^1.12** and Laravel 9–11. PHPStan 2 is not yet supported: the
`Collector` generics and `RuleError` contract changed there and need verification
before widening the constraint — the graph run is not a `larastan 2` environment.

## Dev

```bash
composer install
vendor/bin/pest                      # 17 tests: unit + phpstan integration
```
