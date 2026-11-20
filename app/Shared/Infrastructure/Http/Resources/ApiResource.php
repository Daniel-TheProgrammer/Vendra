<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for every API resource. Single resources and collections are always
 * wrapped in "data" so clients can rely on one envelope.
 */
abstract class ApiResource extends JsonResource
{
    public static $wrap = 'data';
}
