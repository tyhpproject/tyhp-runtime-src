# Sources for this tyhpdef generation

- Composer package: driftingly/rector-laravel 2.6.2
- Layer 1 from generate_tyhpdef --package-path=vendor/driftingly/rector-laravel (parsed PHP autoload; no Reflection)
- Overlay omits Layer 1 `extern class \PHPStan\Reflection\ReflectionProvider` so the `interface` from `tyhpdef/phpstan-phpstan` is the live name. `AbstractRector` and every rule that extends it are omitted (`symplify/rule-doc-generator-contracts` is not wrapped).
- Catalog: see the Tyhp repository THIRD_PARTY.md
