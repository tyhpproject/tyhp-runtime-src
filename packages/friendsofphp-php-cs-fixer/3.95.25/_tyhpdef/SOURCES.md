# Sources for this tyhpdef generation

- Composer package: friendsofphp/php-cs-fixer 3.95.25
- Layer 1 from generate_tyhpdef --source of Token and related public types (full --package-path fails on PHPUnit_Framework_Assert in PhpUnitNamespacedFixer)
- FixerInterface and FixerDefinitionInterface harvested with the tyhpdef/php catalog so \SplFileInfo resolves
- RuleSetInterface and CodeSampleInterface stay in Layer 3
- Catalog: see the Tyhp repository THIRD_PARTY.md
