# Sources for this tyhpdef generation

- Implementation package for `tyhpdef/google-apiclient-services` (google/apiclient-services 0.459.0).
- Service classes are harvested into `tyhpdef/google-apiclient-services-<service>-impl`. Shared bases are `tyhpdef/google-apiclient-services-common-impl`.
- This package requires those public names (`tyhpdef/google-apiclient-services-<service>` and `tyhpdef/google-apiclient-services-common`). Path repositories point at the impl directories.
- Catalog: see the Tyhp repository THIRD_PARTY.md
