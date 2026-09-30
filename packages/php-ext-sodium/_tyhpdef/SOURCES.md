# Sources for this tyhpdef generation

- Extension: `sodium`
- PHP Documentation Group HTML manual (`php_manual_*.html.gz`) — [CC BY 3.0](https://www.php.net/manual/en/cc.license.php)
- Catalog: see the Tyhp repository `THIRD_PARTY.md`

## Stub corpora (Layer 2)

Harvested into `overlays/stubs/`. Raw stub trees are not committed.

- Psalm stubs (MIT) — https://github.com/vimeo/psalm/tree/6.x/stubs
- PHPStan stubs (MIT) — https://github.com/phpstan/phpstan-src/tree/2.2.x/stubs
- PhpStorm stubs (Apache-2.0) — https://github.com/jetbrains/phpstorm-stubs

`PASSWORD_ARGON2*` constants are not in this baseline: they already live in `tyhpdef/php` (`Ext.Standard.tyhpdef`). Regen reintroduces them from sodium Reflection on PHP < 8.4; strip them again so both packages can load together.

