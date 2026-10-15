# Sources for this tyhpdef generation

- Composer package: google/apiclient 2.19.4
- Layer 1 from generate_tyhpdef --package-path=vendor/google/apiclient (parsed PHP autoload; no Reflection)
- `\Google\Exception`, `\Google\Model`, `\Google\Collection`, `\Google\Service`, `\Google\Service\Exception`, and `\Google\Service\Resource` are declared by `tyhpdef/google-apiclient-services-common`. They are not repeated in this file.
- `\Google\Client` and `\Google\Http\Batch` stay in this package. `\Google\Service::getClient()` returns `\Google\Client` and `createBatch()` returns `\Google\Http\Batch`.
- Catalog: see the Tyhp repository THIRD_PARTY.md
