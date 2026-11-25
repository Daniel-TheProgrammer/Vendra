<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Infrastructure\Gateway\FakeGateway;
use App\Modules\Payments\Infrastructure\Listeners\ReleasePaymentsOfCancelledOrder;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Payments';
    }

    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, static function (Application $app): PaymentGateway {
            $driver = $app->make(Config::class)->string('payments.gateway');

            return match ($driver) {
                'fake' => $app->make(FakeGateway::class),
                default => throw new InvalidArgumentException(sprintf('Unknown payment gateway "%s" (payments.gateway).', $driver)),
            };
        });
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(OrderCancelled::class, ReleasePaymentsOfCancelledOrder::class);
    }
}
