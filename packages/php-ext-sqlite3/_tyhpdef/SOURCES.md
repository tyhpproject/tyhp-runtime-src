# Sources for this tyhpdef generation

- Extension: `sqlite3`
- PHP Documentation Group HTML manual (`php_manual_*.html.gz`) — [CC BY 3.0](https://www.php.net/manual/en/cc.license.php)
- Catalog: see the Tyhp repository `THIRD_PARTY.md`

Layer 1 was rebuilt from `tyhp generate_tyhpdef --php-targets=8.2,8.3,8.4,8.5`
snapshots after the writer refused the raw emit: `SQLite3::FUNCTION` is
`T_FUNCTION` and must be spelled `self::FUNCTION as FUNCTION`.

## Stub corpora (Layer 2)

Harvested into `overlays/stubs/`. Raw stub trees are not committed.

- Psalm stubs (MIT) — https://github.com/vimeo/psalm/tree/6.x/stubs
- PHPStan stubs (MIT) — https://github.com/phpstan/phpstan-src/tree/2.2.x/stubs
- PhpStorm stubs (Apache-2.0) — https://github.com/jetbrains/phpstorm-stubs

