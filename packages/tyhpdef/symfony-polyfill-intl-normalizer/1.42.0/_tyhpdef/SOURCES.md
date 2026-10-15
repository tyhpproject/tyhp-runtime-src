# Sources for this tyhpdef generation

- Composer package: symfony/polyfill-intl-normalizer 1.42.0
- Layer 1 from generate_tyhpdef --source=vendor/symfony/polyfill-intl-normalizer --output=./_tyhpdef --output-file=symfony.polyfill-intl-normalizer.tyhpdef --overwrite (PSR-4 maps to `""`, so --package-path has no autoload paths)
- Test/ and Tests/ directories excluded
- Layer 2 stub harvest: empty (no stub corpus declarations for this package)
- Catalog: see the Tyhp repository THIRD_PARTY.md
