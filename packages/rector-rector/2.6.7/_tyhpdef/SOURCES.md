# Sources for this tyhpdef generation

- Composer package: rector/rector 2.6.7
- Layer 1 from generate_tyhpdef --source=vendor/rector/rector/src --include-internal (Composer autoload is only bootstrap.php; rules/ is omitted because PHPDoc imports do not qualify PhpParser node names)
- Catalog: see the Tyhp repository THIRD_PARTY.md
