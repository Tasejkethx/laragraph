# laragraph

Type-resolved **call-graph for Laravel**, built on top of PHPStan/Larastan.

Unlike syntax-based tools (CodeGraph, graphify, deptrac, tree-sitter indexers),
laragraph reads the graph off PHPStan's **resolved types**. That means it sees
edges the others structurally cannot:

- facade → concrete class (`Cache::get()` → the real store)
- Eloquent magic (`User::query()->whereEmail()` → builder methods)
- generics on collections
- and — via a live-container pass — Laravel's dynamic wiring: routes → controllers,
  events → listeners, `Model::observe()`, `dispatch()`, the scheduler.

The graph is stored in SQLite and queried for **impact maps** (callers / callees /
blast-radius), primarily as context for an LLM coding agent.

## Status

Early. Built phase-by-phase:

- **Ф0 — skeleton** ✅ package, service provider, PHPStan extension, SQLite schema.
- **Ф1 — static core** 🟡 `CallEdgeCollector` (instance calls, type-resolved) →
  `GraphSinkRule` → SQLite.
- **Ф2 — Laravel dynamics** ⬜ routes / events / observers / schedule / dispatch.
- **Ф3 — agent queries** ⬜ `graph:impact`, `graph:callers`, `graph:callees`.

## How it works

```
php artisan graph:build
   │
   ├─ PHPStan analyser (laragraph extension)
   │     CallEdgeCollector → per-call-site edges (callee resolved via Scope)
   │        ↓ CollectedDataNode (one virtual node after full analysis)
   │     GraphSinkRule → writes nodes + edges to SQLite
   │
   └─ (Ф2) live Laravel container → routes/listeners/observers/schedule edges
```

`edges.resolved_by` records whether an edge came from static analysis (`phpstan`)
or the live framework (`runtime`) — so the Laravel dynamics that are invisible to
every syntax-based tool are explicitly accounted for.

## Install (into a Laravel app, local path repo)

```jsonc
// composer.json of the target app
"repositories": [
    { "type": "path", "url": "../laragraph" }
],
"require-dev": { "laragraph/laragraph": "*" }
```

Point it at the app's PHPStan config to reuse Larastan (config `laragraph.phpstan_config`),
then:

```bash
php artisan graph:build
```

## Dev

```bash
composer install
# Ф1 smoke test against the fixture:
vendor/bin/phpstan analyse -c tests/phpstan-fixture.neon
sqlite3 /tmp/laragraph-fixture.sqlite 'select * from edges'
```
