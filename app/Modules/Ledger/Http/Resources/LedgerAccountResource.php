<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Domain\AccountBalance;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class LedgerAccountResource extends ApiResource
{
    public function __construct(LedgerAccount $account)
    {
        parent::__construct($account);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LedgerAccount $account */
        $account = $this->resource;
        $balance = new AccountBalance(
            Money::of($account->balance->debit_total ?? 0, $account->currency),
            Money::of($account->balance->credit_total ?? 0, $account->currency),
            $account->normal_balance,
        );

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'normal_balance' => $account->normal_balance->value,
            'currency' => $account->currency->value,
            'balance' => self::balance($balance),
        ];
    }

    /**
     * @return array{debits: string, credits: string, balance: string, balance_minor: int}
     */
    public static function balance(AccountBalance $balance): array
    {
        return [
            'debits' => $balance->debits->toDecimal(),
            'credits' => $balance->credits->toDecimal(),
            'balance' => $balance->balance()->toDecimal(),
            'balance_minor' => $balance->balance()->amount,
        ];
    }
}
