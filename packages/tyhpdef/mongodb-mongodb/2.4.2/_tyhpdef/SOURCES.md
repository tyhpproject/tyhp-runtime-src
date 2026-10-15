# Sources for this tyhpdef generation

- Composer package: mongodb/mongodb 2.4.2
- Layer 1 from generate_tyhpdef --package-path=vendor/mongodb/mongodb (parsed PHP autoload; no Reflection)
- Driver / BSON types come from tyhpdef/php-ext-mongodb (path-repo); not overlay-extern
- `Collection::explain` is omitted: it takes `@internal` `\MongoDB\Operation\Explainable`. Other wrappers use `--include-internal` only when most of the public API is internal, so this method is dropped rather than inventing a different parameter type.
- Catalog: see the Tyhp repository THIRD_PARTY.md
