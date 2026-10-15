# Sources for this tyhpdef generation

- Extension: `mongodb`
- Managed PHP cannot load `mongodb` (TYHP7511); Layer 1 is not Reflection
- Layer 1 from `tyhp generate_tyhpdef --source` on JetBrains phpstorm-stubs `mongodb/` (Apache-2.0), including official driver `.stub.php` files that phpstorm-stubs vendors. Signatures follow php.net.
- PHP Documentation Group HTML manual — [CC BY 3.0](https://www.php.net/manual/en/cc.license.php)
- Catalog: see the Tyhp repository `THIRD_PARTY.md`

No Layer 2 stub harvest: that step runs with `--ext-name` Reflection, which this package cannot use.
