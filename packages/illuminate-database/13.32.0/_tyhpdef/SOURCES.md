# Sources for this tyhpdef generation

- Composer package: illuminate/database 13.32.0
- Layer 1 from generate_tyhpdef --source=vendor/illuminate/database --output=./_tyhpdef --output-file=illuminate.database.tyhpdef --overwrite (PSR-4 maps to `""`, so --package-path has no autoload paths)
- Regenerated 2026-09-16 (overwrite after catalog unique-global short-name fix): `\Illuminate\Database\Connection` / Eloquent `Model` / `Scope` / `Relation` / Connectors `Connector`, not bare globals; T_STATIC harvest rewrite unchanged
- Regenerated 2026-09-16 after harvest omit-cascade / excluded-`@internal` reference strip: Command-extending console types and `ModelInfo` consumers omitted consistently; FQCN names unchanged
- Catalog: see the Tyhp repository THIRD_PARTY.md
