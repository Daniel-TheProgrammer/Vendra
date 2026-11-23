<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Unit;

use App\Modules\Catalog\Domain\PriceWindow;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceWindowTest extends TestCase
{
    private function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant);
    }

    private function window(?string $from, ?string $to): PriceWindow
    {
        return new PriceWindow($from !== null ? $this->at($from) : null, $to !== null ? $this->at($to) : null);
    }

    #[Test]
    public function a_window_is_half_open(): void
    {
        $sale = $this->window('2026-11-27T00:00:00Z', '2026-12-01T00:00:00Z');

        self::assertFalse($sale->contains($this->at('2026-11-26T23:59:59.999999Z')));
        self::assertTrue($sale->contains($this->at('2026-11-27T00:00:00Z')), 'the start is included');
        self::assertTrue($sale->contains($this->at('2026-11-30T23:59:59Z')));
        self::assertFalse($sale->contains($this->at('2026-12-01T00:00:00Z')), 'the end is excluded');
    }

    #[Test]
    public function instants_are_compared_as_instants_whatever_their_offset(): void
    {
        $sale = $this->window('2026-11-27T00:00:00-05:00', null);

        self::assertFalse($sale->contains($this->at('2026-11-27T04:59:59Z')));
        self::assertTrue($sale->contains($this->at('2026-11-27T05:00:00Z')));
    }

    #[Test]
    public function open_ends_extend_forever(): void
    {
        self::assertTrue($this->window(null, '2026-12-01T00:00:00Z')->contains($this->at('1970-01-01T00:00:00Z')));
        self::assertTrue($this->window('2026-12-01T00:00:00Z', null)->contains($this->at('2999-01-01T00:00:00Z')));
        self::assertTrue(PriceWindow::always()->contains($this->at('2026-11-23T12:00:00Z')));
        self::assertTrue(PriceWindow::always()->isAlways());
        self::assertFalse($this->window(null, '2026-12-01T00:00:00Z')->isAlways());
    }

    /**
     * @return iterable<string, array{array{?string, ?string}, array{?string, ?string}, bool}>
     */
    public static function pairs(): iterable
    {
        $nov = ['2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'];

        yield 'identical' => [$nov, $nov, true];
        yield 'back to back' => [$nov, ['2026-12-01T00:00:00Z', '2027-01-01T00:00:00Z'], false];
        yield 'one microsecond of overlap' => [$nov, ['2026-11-30T23:59:59.999999Z', '2027-01-01T00:00:00Z'], true];
        yield 'nested' => [$nov, ['2026-11-10T00:00:00Z', '2026-11-20T00:00:00Z'], true];
        yield 'disjoint' => [$nov, ['2027-03-01T00:00:00Z', '2027-04-01T00:00:00Z'], false];
        yield 'open start reaching in' => [$nov, [null, '2026-11-02T00:00:00Z'], true];
        yield 'open start ending at the start' => [$nov, [null, '2026-11-01T00:00:00Z'], false];
        yield 'open end starting inside' => [$nov, ['2026-11-30T00:00:00Z', null], true];
        yield 'two open ends' => [[null, '2026-01-01T00:00:00Z'], ['2027-01-01T00:00:00Z', null], false];
        yield 'open end after open start' => [['2026-01-01T00:00:00Z', null], [null, '2027-01-01T00:00:00Z'], true];
    }

    /**
     * @param  array{?string, ?string}  $a
     * @param  array{?string, ?string}  $b
     */
    #[Test]
    #[DataProvider('pairs')]
    public function overlap_is_symmetric_and_respects_the_open_end(array $a, array $b, bool $overlaps): void
    {
        self::assertSame($overlaps, $this->window(...$a)->overlaps($this->window(...$b)));
        self::assertSame($overlaps, $this->window(...$b)->overlaps($this->window(...$a)));
    }

    #[Test]
    public function a_window_has_ended_once_its_end_is_reached(): void
    {
        $sale = $this->window('2026-11-27T00:00:00Z', '2026-12-01T00:00:00Z');

        self::assertFalse($sale->hasEndedBy($this->at('2026-11-30T00:00:00Z')));
        self::assertTrue($sale->hasEndedBy($this->at('2026-12-01T00:00:00Z')));
        self::assertFalse($this->window('2026-11-27T00:00:00Z', null)->hasEndedBy($this->at('2999-01-01T00:00:00Z')));
    }

    #[Test]
    public function an_empty_or_inverted_window_is_refused(): void
    {
        foreach ([['2026-12-01T00:00:00Z', '2026-12-01T00:00:00Z'], ['2026-12-02T00:00:00Z', '2026-12-01T00:00:00Z']] as [$from, $to]) {
            try {
                $this->window($from, $to);
                self::fail(sprintf('[%s, %s) must be refused.', $from, $to));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('end after it starts', $exception->getMessage());
            }
        }
    }
}
