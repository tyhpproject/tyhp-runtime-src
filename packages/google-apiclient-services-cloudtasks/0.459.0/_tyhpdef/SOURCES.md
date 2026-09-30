# Sources for this tyhpdef generation

- Composer package: google/apiclient-services 0.459.0, CloudTasks only
- PSR-4 is `Google\Service\` → `src` (not `""`). `--source` limits the harvest to this service. `--package-path` would harvest every API.
- Layer 1 from `generate_tyhpdef --source=runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient-services/src/CloudTasks.php,runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient-services/src/CloudTasks --output=runtime/packages/google-apiclient-services-cloudtasks/0.459.0/_tyhpdef --output-file=google.apiclient-services-cloudtasks.tyhpdef --overwrite`
- Depends on `tyhpdef/google-apiclient-services-common` for `\Google\Service`, `\Google\Service\Resource`, `\Google\Model`, and `\Google\Collection`
- Catalog: see the Tyhp repository THIRD_PARTY.md
