<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/*
 * Rector keeps the codebase on modern PHP idioms. CI runs it in dry-run mode and
 * fails on any suggested change; apply locally with `make rector-fix`.
 *
 * The language level follows composer.json ("php": "^8.2"), not the runtime image.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withCache(__DIR__.'/storage/framework/rector');
