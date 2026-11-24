<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Unit;

use App\Modules\Catalog\Domain\InsufficientStock;
use App\Modules\Catalog\Domain\StockLevel;
use App\Modules\Catalog\Domain\StockNotReserved;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StockLevelTest extends TestCase
{
    private function level(int $onHand, int $reserved): StockLevel
    {
        return new StockLevel('variant-1', $onHand, $reserved);
    }

    #[Test]
    public function available_is_on_hand_minus_reserved(): void
    {
        self::assertSame(7, $this->level(10, 3)->available());
    }

    #[Test]
    public function adjustments_move_on_hand_and_protect_reserved_units(): void
    {
        self::assertSame(15, $this->level(10, 3)->adjust(5)->onHand);
        self::assertSame(3, $this->level(10, 3)->adjust(-7)->onHand);

        $this->expectException(InsufficientStock::class);
        $this->level(10, 3)->adjust(-8);
    }

    #[Test]
    public function reserving_never_exceeds_what_is_available(): void
    {
        $level = $this->level(10, 3)->reserve(7);

        self::assertSame(10, $level->reserved);
        self::assertSame(0, $level->available());

        try {
            $level->reserve(1);
            self::fail('Reserving beyond availability must be refused.');
        } catch (InsufficientStock $exception) {
            self::assertSame(['variant_id' => 'variant-1', 'available' => 0, 'requested' => 1], $exception->extensions());
            self::assertSame(409, $exception->status());
        }
    }

    #[Test]
    public function releasing_returns_units_but_never_more_than_were_reserved(): void
    {
        self::assertSame(1, $this->level(10, 3)->release(2)->reserved);

        $this->expectException(StockNotReserved::class);
        $this->level(10, 3)->release(4);
    }

    #[Test]
    public function shipping_takes_reserved_units_off_the_shelf_and_nothing_else(): void
    {
        $shipped = $this->level(10, 3)->ship(2);

        self::assertSame(8, $shipped->onHand);
        self::assertSame(1, $shipped->reserved);
        self::assertSame(7, $shipped->available(), 'what was free to sell stays free');

        $this->expectException(StockNotReserved::class);
        $this->level(10, 3)->ship(4);
    }

    #[Test]
    public function operations_return_new_levels_and_leave_the_original_untouched(): void
    {
        $original = $this->level(10, 0);
        $original->reserve(4);

        self::assertSame(0, $original->reserved);
    }

    /**
     * @return iterable<string, array{callable(StockLevel): StockLevel}>
     */
    public static function meaninglessQuantities(): iterable
    {
        yield 'zero adjustment' => [static fn (StockLevel $level): StockLevel => $level->adjust(0)];
        yield 'zero reservation' => [static fn (StockLevel $level): StockLevel => $level->reserve(0)];
        yield 'negative reservation' => [static fn (StockLevel $level): StockLevel => $level->reserve(-1)];
        yield 'negative release' => [static fn (StockLevel $level): StockLevel => $level->release(-1)];
        yield 'zero shipment' => [static fn (StockLevel $level): StockLevel => $level->ship(0)];
    }

    /**
     * @param  callable(StockLevel): StockLevel  $operation
     */
    #[Test]
    #[DataProvider('meaninglessQuantities')]
    public function meaningless_quantities_are_programming_errors(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);

        $operation($this->level(10, 2));
    }

    #[Test]
    public function an_impossible_level_cannot_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->level(2, 3);
    }
}
