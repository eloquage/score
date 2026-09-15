# TypePHP contract for `eloquage/score`

`src/` is the authoritative pure-PHP implementation and the consumer
fallback. The package remains framework-agnostic, does not require
`swoole/typephp`, and does not promise that an optional extension exists or
has runtime parity with the PHP implementation.

## Configuration

The checked-in example remains extension mode with no `main()` requirement:

```yaml
name: eloquage-score
mode: ext
php-version: "8.5"
optimize: 2
job: 4
build-dir: build
cxx-std: c++17

sources:
  - src
  - native

ignore:
  - tests
  - vendor
```

`php-version: "8.5"` is the TypePHP source-syntax target, not the consumer
runtime requirement. Consumers use PHP `^8.3`. `native/` may remain empty;
the PHP implementation must continue to work without a `.so` or `.dll`.

## Explicit skip for this change

Native implementation and the Docker `tpc` compile are intentionally skipped
for the `score-exported-linear-trees` change. The new boundary is dominated
by JSON decoding, dynamic associative/named-feature validation, union-shaped
public inputs, and recursive tree parsing. There is no measured typed hot
loop that currently justifies adding native code.

The future profiling boundary is the normalized tree walk, followed by the
linear sum if measurements support it. That belongs to a follow-up with its
own compatibility and extension-load evidence. This change therefore does
not claim that `docker/typephp/build-package.sh score`, `tpc`, an optional
extension, or extension-enabled tests were run.

The pure-PHP package checks remain mandatory:

```bash
vendor/bin/pest
vendor/bin/pest --coverage --min=90
```

## Future Docker workflow

When a follow-up explicitly adds compatible native sources, compile only
through the shared Docker builder:

```bash
docker/typephp/build-package.sh score
```

The default image is
`ghcr.io/eloquage/typephp-builder:latest`; `ELOQUAGE_TYPEPHP_IMAGE` may
select a compatible pinned or locally built image. A future successful build
would be additional evidence only: it would not replace the pure-PHP source,
tests, or coverage gate.

Follow the repository TypePHP skill and its references before attempting that
workflow. Do not add a consumer-facing TypePHP Composer dependency, put
dynamic JSON/tree values into a native object, or describe generated local
artifacts as a supported release extension without a verified Docker build.
