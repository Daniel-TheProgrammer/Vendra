<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Requests;

use App\Modules\Webhooks\Application\Actions\SubscriptionData;
use App\Modules\Webhooks\Application\Services\EventTypes;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /webhooks/subscriptions and PATCH /webhooks/subscriptions/{id}:
 *
 *   { "url": "https://…", "event_types": ["orders.order_placed"] | ["*"],
 *     "description": "…", "status": "active" | "paused" (PATCH only) }
 */
final class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'url' => [$required, 'string', 'max:2048', 'url:https'],
            'event_types' => [$required, 'array', 'min:1', 'max:50'],
            'event_types.*' => ['required', 'string', 'distinct', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => [$this->isMethod('POST') ? 'prohibited' : 'sometimes', Rule::enum(SubscriptionStatus::class)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $types = $this->input('event_types');

                if (! is_array($types) || $validator->errors()->has('event_types.*')) {
                    return;
                }

                $unknown = app(EventTypes::class)->unknown(array_values(array_filter($types, is_string(...))));

                if ($unknown !== []) {
                    $validator->errors()->add('event_types', sprintf('Unknown event type(s): %s. GET /webhooks/event-types lists them.', implode(', ', $unknown)));
                }
            },
        ];
    }

    public function subscriptionData(): SubscriptionData
    {
        /** @var list<string>|null $types */
        $types = $this->has('event_types') ? array_values($this->input('event_types')) : null;

        return new SubscriptionData(
            url: $this->has('url') ? $this->string('url')->value() : null,
            eventTypes: $types,
            description: $this->filled('description') ? $this->string('description')->value() : null,
            updatesDescription: $this->exists('description'),
        );
    }

    public function status(): ?SubscriptionStatus
    {
        return $this->enum('status', SubscriptionStatus::class);
    }
}
