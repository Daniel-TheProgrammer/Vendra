<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Import;

use App\Modules\Catalog\Domain\ImportStatus;
use App\Modules\Catalog\Infrastructure\Jobs\ProcessProductImport;
use App\Modules\Catalog\Infrastructure\Models\ProductImport;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Accepts a CSV file: checks its structure while the client is still
 * waiting (so a broken header is a 422, not a failed job an hour later),
 * keeps a copy where every worker can read it and queues the work.
 */
final readonly class StartProductImport
{
    public function __construct(
        private Filesystems $filesystems,
        private Config $config,
        private Bus $bus,
    ) {}

    /**
     * @param  string  $source  a readable local path, such as an upload's temporary file
     *
     * @throws InvalidProductCsv
     */
    public function __invoke(string $tenantId, ?string $actorId, string $source, string $filename): ProductImport
    {
        $csv = $this->withStream($source, ProductCsv::read(...));

        $id = (string) Str::uuid7();
        $disk = $this->config->string('catalog.imports.disk', 'local');
        $path = sprintf('imports/%s/%s.csv', $tenantId, $id);

        $this->withStream($source, function ($stream) use ($disk, $path): void {
            if (! $this->filesystems->disk($disk)->writeStream($path, $stream)) {
                throw new RuntimeException(sprintf('Could not store the import file at %s:%s.', $disk, $path));
            }
        });

        $import = new ProductImport([
            'status' => ImportStatus::Pending,
            'filename' => mb_substr($filename, 0, 255),
            'disk' => $disk,
            'path' => $path,
            'total_rows' => $csv->rowCount(),
            'actor_id' => $actorId,
        ]);
        $import->id = $id;
        $import->tenant_id = $tenantId;
        $import->save();

        $this->bus->dispatch((new ProcessProductImport($tenantId, $id))->afterCommit());

        return $import;
    }

    /**
     * @template T
     *
     * @param  callable(resource): T  $use
     * @return T
     */
    private function withStream(string $path, callable $use): mixed
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Cannot read %s.', $path));
        }

        try {
            return $use($stream);
        } finally {
            fclose($stream);
        }
    }
}
