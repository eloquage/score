# eloquage/score

Framework-agnostic, inference-only PHP scoring for the owned `eloquage-score`
JSON envelope: local linear models and bounded binary trees/forests.

- Composer: `eloquage/score`
- Entrypoint: `Eloquage\Score\Score`
- This package is framework-agnostic. The Laravel app at the monorepo root is a local test bench only.

## Layout

- `src/` — public PHP API (source of truth)
- `native/` — optional TypePHP AOT sources (empty today)
- `tests/` — Pest 5
- `TYPEPHP.md` — extension build contract
- `project.yml.example` — TypePHP project config (copy to gitignored `project.yml`)

## Inference boundary

`src/` is the pure-PHP source of truth. `Score::load()` accepts a readable
local JSON path and returns the same `Score` entrypoint used by the existing
identity call. It validates and normalizes one versioned `eloquage-score`
document, then exposes `predict()` and explicit binary-probability
`predictProba()` operations.

The package deliberately excludes pickle, ONNX, Python, HTTP fetching,
training, persistence, preprocessing, and external model runtimes. Pickle is
excluded because executing opaque Python serialization would weaken
portability and auditability and would make loading fail open around a
runtime that is not part of this package. Unsupported shapes fail closed at
the local JSON boundary.

## Commands

```bash
composer test
composer format
vendor/bin/pest --coverage --min=90
```

## Conventions

- No Illuminate / Laravel service providers.
- Always ship a pure-PHP fallback. Never `require` `swoole/typephp`.
- Consumers: PHP 8.3+. Package CI: PHP 8.4. TypePHP compile: PHP 8.5 syntax.

## TypePHP

Extension mode only (`mode: ext`). Build in Docker, not on the host:

```bash
# harness (default: ghcr.io/eloquage/typephp-builder)
docker/typephp/build-package.sh score

# this repo
docker run --rm -v "$PWD":/src -w /src \
  "${ELOQUAGE_TYPEPHP_IMAGE:-ghcr.io/eloquage/typephp-builder:latest}" \
  sh -c 'test -f project.yml || cp project.yml.example project.yml; tpc.php project.yml'
```

See `TYPEPHP.md`. Linux containers only for the shared builder. Consumer
installs do not require a native extension.

For this change, native implementation and the Docker `tpc` compile are
explicitly skipped. JSON parsing, dynamic feature validation, and recursive
tree walking have no measured typed hot loop that justifies widening the
native surface. Keep the pure-PHP coverage gate mandatory; do not claim an
extension build or TypePHP runtime parity from generated artifacts.

## Harness demo

Public behavior must be exercisable from the laravel-x welcome page (`/` → `resources/views/welcome.blade.php`) with a Feature test.

## Humans vs agents

- README — install/usage for humans
- This file — agent context
- TYPEPHP.md — AOT / Docker / release
