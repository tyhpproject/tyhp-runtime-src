# Sources for this tyhpdef generation

- Composer package: filament/forms 5.8.2
- Layer 1 from generate_tyhpdef --package-path=vendor/filament/forms (parsed PHP autoload; no Reflection)
- Layer 3 omits TipTap-typed RichEditor APIs and TipTap extension classes, plus `TableSelectLivewireComponent` (filament/tables and ueberdosis/tiptap-php are not included by this wrapper).
- Catalog: see the Tyhp repository THIRD_PARTY.md
