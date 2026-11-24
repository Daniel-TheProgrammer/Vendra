<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

use InvalidArgumentException;

final readonly class ShippingAddress
{
    public function __construct(
        public string $name,
        public string $line1,
        public ?string $line2,
        public string $city,
        public ?string $region,
        public string $postalCode,
        /** ISO 3166-1 alpha-2, upper case. */
        public string $country,
    ) {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException('The country must be an ISO 3166-1 alpha-2 code such as "GB".');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $optional = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        return new self(
            name: (string) ($data['name'] ?? ''),
            line1: (string) ($data['line1'] ?? ''),
            line2: $optional('line2'),
            city: (string) ($data['city'] ?? ''),
            region: $optional('region'),
            postalCode: (string) ($data['postal_code'] ?? ''),
            country: strtoupper((string) ($data['country'] ?? '')),
        );
    }

    /**
     * @return array{name: string, line1: string, line2: string|null, city: string, region: string|null, postal_code: string, country: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ];
    }
}
