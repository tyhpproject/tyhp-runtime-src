# Sources for this tyhpdef generation

- Composer package: symfony/process 8.1.7
- Layer 1 from generate_tyhpdef --source=vendor/symfony/process --output=./_tyhpdef --output-file=symfony.process.tyhpdef --overwrite (PSR-4 maps to `""`, so --package-path has no autoload paths)
- Test/ and Tests/ directories excluded
- Layer 2 stub harvest: empty (no stub corpus declarations for this package)
- Catalog: see the Tyhp repository THIRD_PARTY.md
