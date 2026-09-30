# Sources for this tyhpdef generation

- Composer package: filament/tables 5.8.2
- Layer 1 from generate_tyhpdef --package-path=vendor/filament/tables (parsed PHP autoload; no Reflection)
- Layer 3 types `ColumnGroup` column lists as `array<int|string, Column>` and omits QueryBuilder filters, constraints, and icon aliases (filament/query-builder is not wrapped).
- Catalog: see the Tyhp repository THIRD_PARTY.md
