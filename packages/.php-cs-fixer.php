<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

/**
 * PER-CS 3.0 style gate for Tyhp-emitted PHP and handwritten plugin PHP under dist/.
 *
 * PHP_CodeSniffer does not ship a complete PER-CS 3.0 ruleset (PSR-12 is PER-CS 1.0).
 * PHP-CS-Fixer @PER-CS3x0 is the authoritative check. Tyhp-generated names (__tyhp*,
 * generic factories, Promise::_async / ::_await) are left as emitted — this ruleset
 * does not rename them. Property-hook files that a given fixer version cannot parse
 * should be added to Finder exclusions until the tokenizer understands PHP 8.4 hooks
 * (PHPCSStandards/PHP_CodeSniffer#731 is the analogous PHPCS gap).
 */
$finder = Finder::create()
    ->in(__DIR__ . '/dist')
    ->name('*.php')
    ->exclude('vendor')
    ->notPath('#tyhp_src#')
    ->notPath('#/tests/#');

$config = (new Config())
    ->setRules([
        '@PER-CS3x0' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(sys_get_temp_dir() . '/tyhp-runtime-php-cs-fixer.cache');

if (method_exists($config, 'setUnsupportedPhpVersionAllowed')) {
    $config->setUnsupportedPhpVersionAllowed(true);
}

if (class_exists(ParallelConfigFactory::class)) {
    $config->setParallelConfig(ParallelConfigFactory::detect());
}

return $config;
