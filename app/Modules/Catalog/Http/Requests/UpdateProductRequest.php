<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Application\Commands\UpdateProductCommand;
use App\Modules\Catalog\Domain\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * PATCH semantics: absent fields stay as they are; "description": null clears it.
 */
final class UpdateProductRequest extends FormRequest
{
    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(ProductStatus::editableValues())],
        ];
    }

    public function toCommand(string $productId, ?int $expectedVersion): UpdateProductCommand
    {
        $description = $this->has('description') && $this->filled('description')
            ? $this->string('description')->trim()->value()
            : null;

        return new UpdateProductCommand(
            productId: $productId,
            expectedVersion: $expectedVersion,
            name: $this->has('name') ? $this->string('name')->trim()->value() : null,
            status: $this->enum('status', ProductStatus::class),
            updatesDescription: $this->has('description'),
            description: $description,
        );
    }
}
