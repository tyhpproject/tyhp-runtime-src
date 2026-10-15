# Sources for this tyhpdef generation

- Extension: `uri`
- Hand-written from PHP 8.5.8 Reflection (`extension_loaded('uri')` on Tyhp-managed PHP).
  `generate_tyhpdef --php-targets=8.5` mapped 9 classes but parse-check refused the
  dump (`static` return types) and wrote no Layer 1 file.
- PHP Documentation Group — [CC BY 3.0](https://www.php.net/manual/en/cc.license.php)
- Catalog: see the Tyhp repository `THIRD_PARTY.md`

Contents are gated `declare(php=">=8.5")`.
