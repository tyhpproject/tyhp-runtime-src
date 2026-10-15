# Sources for this tyhpdef generation

- Composer package: symfony/phpunit-bridge 7.4.17
- Layer 1 from generate_tyhpdef --source (PSR-4 maps to `""`, so --package-path has no autoload paths)
- Test/ and Tests/ directories excluded
- Isolated PHP copy used at generate time so Track B does not read the upstream composer.json (duplicate `php` require keys)
- Catalog: see the Tyhp repository THIRD_PARTY.md
