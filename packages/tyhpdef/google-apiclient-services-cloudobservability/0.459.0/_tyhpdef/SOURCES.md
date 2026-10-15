# Sources for this tyhpdef generation

- Composer package: google/apiclient-services 0.459.0, CloudObservability only
- PSR-4 is `Google\Service\` → `src` (not `""`). `--source` limits the harvest to this service. `--package-path` would harvest every API.
- Layer 1 from `generate_tyhpdef --source=runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient-services/src/CloudObservability.php,runtime/packages/google-apiclient-services/0.459.0/vendor/google/apiclient-services/src/CloudObservability --output=runtime/packages/google-apiclient-services-cloudobservability/0.459.0/_tyhpdef --output-file=google.apiclient-services-cloudobservability.tyhpdef --overwrite`
- Depends on `tyhpdef/google-apiclient-services-common` for `\Google\Service`, `\Google\Service\Resource`, `\Google\Model`, and `\Google\Collection`
- Catalog: see the Tyhp repository THIRD_PARTY.md
