<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Idempotency;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * What "the same request" means for an Idempotency-Key: same method, path,
 * query, principal and payload. The payload is compared by meaning, not by
 * bytes: JSON object key order and whitespace do not matter, the order of
 * list items does. Uploaded files are compared by content.
 */
final class RequestFingerprint
{
    public static function of(Request $request): string
    {
        $user = $request->user();
        $query = $request->query();
        ksort($query);

        $files = [];

        foreach ($request->allFiles() as $field => $file) {
            foreach (is_array($file) ? $file : [$file] as $index => $upload) {
                if ($upload instanceof UploadedFile) {
                    $files[$field.'.'.$index] = hash_file('sha256', (string) $upload->getRealPath());
                }
            }
        }

        ksort($files);

        return hash('sha256', json_encode([
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'query' => self::canonical($query),
            'principal' => $user !== null ? $user::class.':'.$user->getAuthIdentifier() : null,
            'body' => self::canonical($request->except(array_keys($request->allFiles()))),
            'files' => $files,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
