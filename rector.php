<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withPhpSets()
    ->withImportNames(importShortClasses: false)
    ->withSkip([
        ReadOnlyClassRector::class,
    ]);
