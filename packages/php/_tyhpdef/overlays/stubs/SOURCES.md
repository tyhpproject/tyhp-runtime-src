# Sources for this tyhpdef generation

Layer 2 stub overlays harvested by `tyhp generate_tyhpdef` into `overlays/stubs/`.
Raw stub trees are not committed. Array-shape structs that are Tyhp-owned live in
Layer 3 (`overlays/*.tyhpdef`), not here.

## Stub corpora (Layer 2 tyhpdef harvest)

- Psalm stubs (MIT) — https://github.com/vimeo/psalm/tree/6.x/stubs
- PHPStan stubs (MIT) — https://github.com/phpstan/phpstan-src/tree/2.2.x/stubs
- Phan stubs (MIT) — https://github.com/phan/phan/tree/v6/internal/stubs
- PhpStorm stubs (Apache-2.0) — https://github.com/jetbrains/phpstorm-stubs

See the Tyhp repository `THIRD_PARTY.md`.
