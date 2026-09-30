# Sources for this tyhpdef generation

- Composer package: laravel/framework 13.32.0
- Layer 1 from generate_tyhpdef --source=vendor/laravel/framework/src/Illuminate/Foundation,vendor/laravel/framework/src/Illuminate/Concurrency --output=./_tyhpdef --output-file=laravel.framework.tyhpdef --overwrite
- Illuminate split packages (Auth, Database, Http, Support, Testing, …) are not harvested here; they load via extra.tyhp.require
- `illuminate/concurrency` has no split tyhpdef; Concurrency types are owned here
- Catalog: see the Tyhp repository THIRD_PARTY.md
