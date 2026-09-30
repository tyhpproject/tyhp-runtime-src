# Sources for this tyhpdef generation

- Composer package: phpstan/phpstan 2.2.14
- Layer 1: generate_tyhpdef --package-path only emits PharAutoloader; --source of extracted phpstan.phar `src/` emitted TrinaryLogic (Rule/Scope/Type fail to parse on unresolved PhpParser/foreign types)
- Layer 3 copies the public extension contracts (Rule, RuleError, RuleErrorBuilder, Scope, Type) from the extracted PHAR source
- Catalog: see the Tyhp repository THIRD_PARTY.md
