# Sources for this tyhpdef generation

- Shared bases harvested from `google/apiclient` 2.19.4 under the services package vendor (`vendor/google/apiclient/src`). `google/apiclient-services` has no shared base classes; each `src/<Service>.php` plus `src/<Service>/` tree is one API.
- Layer 1 from `generate_tyhpdef --source=runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient/src/Exception.php,runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient/src/Model.php,runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient/src/Collection.php,runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient/src/Service.php,runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient/src/Service --output=runtime/packages/google-apiclient-services-common/0.459.0/_tyhpdef --output-file=google.apiclient-services-common.tyhpdef --overwrite`
- Declarations: `\Google\Exception`, `\Google\Model`, `\Google\Collection`, `\Google\Service`, `\Google\Service\Exception`, `\Google\Service\Resource`
- `\Google\Client` and `\Google\Http\Batch` are referenced by `\Google\Service` and declared by `tyhpdef/google-apiclient`
- Catalog: see the Tyhp repository THIRD_PARTY.md
