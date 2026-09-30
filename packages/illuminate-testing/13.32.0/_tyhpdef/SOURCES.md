# Sources for this tyhpdef generation

- Composer package: illuminate/testing 13.32.0
- Layer 1 from generate_tyhpdef --source=vendor/illuminate/testing --output=./_tyhpdef --output-file=illuminate.testing.tyhpdef --overwrite (PSR-4 maps to `""`, so --package-path has no autoload paths)
- Test/ and Tests/ directories excluded (none in the Packagist dist)
- Catalog: see the Tyhp repository THIRD_PARTY.md
- Layer 1 `externs.tyhpdef` does not redeclare `\Mockery\MockInterface`; illuminate-support already placeholders that name.
