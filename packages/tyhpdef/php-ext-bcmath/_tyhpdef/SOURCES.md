# Sources for this tyhpdef generation

- Extension: `bcmath`
- PHP Documentation Group HTML manual (`php_manual_*.html.gz`) — [CC BY 3.0](https://www.php.net/manual/en/cc.license.php)
- Catalog: see the Tyhp repository `THIRD_PARTY.md`

## Stub corpora (Layer 2)

Raw stub trees are not committed. Psalm (MIT), PHPStan (MIT), and PhpStorm (Apache-2.0) corpora were consulted; see NOTICE and the Tyhp repository `THIRD_PARTY.md`.

Layer 1 gates `BcMath\Number` (`#[\Tyhp\Php(">=8.4")]`) and `bcceil`/`bcfloor`/`bcround`/`bcdivmod` (`declare(php=">=8.4")`). The generated Layer 2 file was only `partial class Number` and is omitted: overlay-partial of that gated class reports TYHP4303 on PHP 8.4 (same overlay-replace-of-gated-symbol issue as `tyhpdef/php-ext-mbstring`). Layer 1 is kept.

