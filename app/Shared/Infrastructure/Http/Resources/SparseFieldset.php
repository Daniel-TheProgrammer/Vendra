<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ?fields=name,status — the client names the top-level attributes it wants
 * and the resource drops the rest. "id" is always returned, so every item
 * stays addressable. Without the parameter the full representation is sent.
 *
 * Unknown names are rejected rather than ignored: a typo must not silently
 * hand a client less than it thinks it asked for.
 */
final readonly class SparseFieldset
{
    public const PARAMETER = 'fields';

    /**
     * @param  list<string>|null  $fields  null means every field
     */
    private function __construct(private ?array $fields) {}

    public static function all(): self
    {
        return new self(null);
    }

    /**
     * @param  list<string>  $available  the resource's top-level attribute names
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, array $available): self
    {
        if (! $request->has(self::PARAMETER)) {
            return self::all();
        }

        $raw = $request->query(self::PARAMETER);

        if (! is_string($raw) || strlen($raw) > 500) {
            throw ValidationException::withMessages([self::PARAMETER => 'The fields parameter must be a comma-separated list of attribute names.']);
        }

        $requested = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $field): bool => $field !== '')));
        $unknown = array_diff($requested, $available);

        if ($requested === [] || $unknown !== []) {
            throw ValidationException::withMessages([self::PARAMETER => sprintf(
                'Unknown or missing field name. Available: %s.',
                implode(', ', $available),
            )]);
        }

        return new self(array_values(array_unique(['id', ...$requested])));
    }

    public function includes(string $field): bool
    {
        return $this->fields === null || in_array($field, $this->fields, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function apply(array $attributes): array
    {
        if ($this->fields === null) {
            return $attributes;
        }

        return array_intersect_key($attributes, array_flip($this->fields));
    }
}
