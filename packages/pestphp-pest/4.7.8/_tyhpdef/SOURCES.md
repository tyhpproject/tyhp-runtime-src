# Sources for this tyhpdef generation

- Composer package: pestphp/pest 4.7.8
- Layer 1 from generate_tyhpdef --package-path=vendor/pestphp/pest (parsed PHP autoload; no Reflection)
- Global `expect` / `test` / `it` live in `src/Functions.php` inside `function_exists` guards, so generate emits no declarations for them; Layer 3 copies those signatures from the PHP source
- Catalog: see the Tyhp repository THIRD_PARTY.md
