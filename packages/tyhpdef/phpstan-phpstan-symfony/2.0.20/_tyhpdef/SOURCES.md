# Sources for this tyhpdef generation

- Composer package: phpstan/phpstan-symfony 2.0.20
- Layer 1 from generate_tyhpdef --package-path=vendor/phpstan/phpstan-symfony (parsed PHP autoload; no Reflection)
- Layer 3: `_tyhpdef/overlays/phpstan.phpstan-symfony.tyhpdef` — Service/ServiceTag list shapes and Serializer class-string from the PHP source
- Catalog: see the Tyhp repository THIRD_PARTY.md
