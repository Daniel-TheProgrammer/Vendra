<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesVariants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreVariantRequest extends FormRequest
{
    use ValidatesVariants;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->variantRules('');
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateVariantPayload($validator, '', $this->all()),
        ];
    }

    public function variant(): VariantData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $this->variantFrom($validated);
    }
}
