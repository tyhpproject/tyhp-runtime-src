# Contributing to tyhp-runtime-src

Issues and pull requests are welcome. This repository holds Tyhp runtime
package source. Compiler bugs belong in
[tyhp](https://github.com/tyhpproject/tyhp).

## Legal

You will need to complete a Contributor License Agreement (CLA). Briefly, this
agreement testifies that you are granting us permission to use the submitted
change according to the terms of the project's license, and that the work being
submitted is under appropriate copyright. Upon submitting a pull request, you
will automatically be given instructions on how to sign the CLA.

## Tests

Build the sibling compiler, then lint tyhpdef packages from this repo:

```bash
dotnet build ../tyhp/tyhp.csproj
TYHP_DLL=../tyhp/bin/Debug/net9.0/tyhp.dll ./packages/test-all-tyhpdef.sh
```

A compiled helper can be rebuilt with `./packages/base-build-all.sh <package>`.
Do not hand-edit generated PHP under `packages/*/src/`. Fix Tyhp first
(compiler, checker, emitter, or tyhpdefs) and re-emit.

Owner-maintained type definitions follow [TYHPDEF_OWNERSHIP.md](TYHPDEF_OWNERSHIP.md).

## Code of conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## License

Contributions are under the [Apache License 2.0](LICENSE.txt).
