# Sources for this tyhpdef generation

- Composer package: psalm/plugin-laravel 4.16.1
- Layer 1 from generate_tyhpdef --package-path=vendor/psalm/plugin-laravel (parsed PHP autoload; no Reflection)
- Layer 3 completes `Plugin` from vendor PHP (`implements PluginEntryPointInterface`; `__invoke` takes `RegistrationInterface`)
- Catalog: see the Tyhp repository THIRD_PARTY.md
