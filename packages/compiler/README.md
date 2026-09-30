# tyhp/compiler

PHP shim for the native Tyhp CLI. After Composer installs this package, download the compiler with:

```bash
vendor/bin/tyhp --install-binary
```

Composer does not run this package’s own scripts. Apps should invoke `--install-binary` from a **root** `post-autoload-dump` script (or by hand if that hook was skipped with `--no-scripts`).

The downloaded native binary is stored next to this package at `native/tyhp` (`native/tyhp.exe` on Windows), pinned to this package’s Composer version (GitHub release tag `v` + that version).
