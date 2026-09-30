# Sources for this tyhpdef generation

- Composer package: api-platform/core 4.4.0
- Layer 1 from generate_tyhpdef --package-path=vendor/api-platform/core --include-internal --overwrite (parsed PHP autoload; no Reflection)
- Catalog: see the Tyhp repository THIRD_PARTY.md
- Missing required wrappers: `symfony/asset`, `symfony/web-link`, `symfony/deprecation-contracts`. Overlay omits types that extend/implement unwrapped peers; WebLink names used in signatures are hand `extern` in `backers.extern.tyhpdef`.
