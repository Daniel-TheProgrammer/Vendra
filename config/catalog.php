<?php

declare(strict_types=1);

return [
    'imports' => [
        // Where uploaded CSV files are kept until their import has run. Every
        // web and worker process must see the same disk: "local" works while
        // they share a volume (as in compose.yaml); use an object store such
        // as s3 once they run on separate hosts.
        'disk' => env('CATALOG_IMPORT_DISK', 'local'),

        // Upload limit in kilobytes. Rows are capped separately (ProductCsv::MAX_ROWS).
        'max_kilobytes' => (int) env('CATALOG_IMPORT_MAX_KB', 5120),
    ],
];
