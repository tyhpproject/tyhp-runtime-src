# Sources for this tyhpdef generation

- Composer package: orchestra/testbench 11.2.0
- This is a forwarding wrapper: Packagist dist is a testing metapackage with no production `autoload` PHP. Types live in `orchestra/testbench-core` (`tyhpdef/orchestra-testbench-core`).
- Layer 1 is empty (NOTICE-only). `tyhp generate_tyhpdef --package-path=vendor/orchestra/testbench` collected no PHP source. Do not invent Layer 1 from workbench, testbench-core, or GitHub.
- Catalog: see the Tyhp repository THIRD_PARTY.md
