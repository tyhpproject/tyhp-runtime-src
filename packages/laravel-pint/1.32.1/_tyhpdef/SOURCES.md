# Sources for this tyhpdef generation

- Composer package: laravel/pint 1.32.1
- Layer 1 from `Phar::extractTo` of the Packagist `builds/pint` phar (copied to a `.phar` name so PHP would open it), then `generate_tyhpdef --source` on the extracted `app/` tree (`App\\` only). Bundled `PhpCsFixer\\` and other foreign phar trees were not harvested.
- Layer 2 stub harvest: empty (no stub corpus for this package)
- Catalog: see the Tyhp repository THIRD_PARTY.md
