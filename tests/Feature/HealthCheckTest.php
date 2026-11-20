<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    #[Test]
    public function the_liveness_endpoint_answers_without_dependencies(): void
    {
        $this->get('/up')->assertOk();
    }
}
