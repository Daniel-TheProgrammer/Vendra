<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesVariants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreProductRequest extends FormRequest
{
    use ValidatesVariants;

    public const MAX_VARIANTS = 100;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $variants = $this->variantRules('variants.*.');
        $variants['variants.*.sku'][] = 'distinct:ignore_case';

        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', Rule::in(ProductStatus::editableValues())],
            'variants' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_VARIANTS],
            'variants.*' => ['required', 'array'],
            ...$variants,
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('variants', []) as $index => $variant) {
                    $this->validateVariantPayload($validator, 'variants.'.$index.'.', $variant);
                }
            },
        ];
    }

    public function productName(): string
    {
        return $this->string('name')->trim()->value();
    }

    public function description(): ?string
    {
        return $this->filled('description') ? $this->string('description')->trim()->value() : null;
    }

    public function status(): ProductStatus
    {
        return $this->enum('status', ProductStatus::class) ?? ProductStatus::Draft;
    }

    /**
     * @return non-empty-list<VariantData>
     */
    public function variants(): array
    {
        /** @var non-empty-list<array<string, mixed>> $variants */
        $variants = $this->validated('variants');

        return array_map($this->variantFrom(...), $variants);
    }
}
