<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Ledger\Application\Queries\TrialBalance;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Http\Rfc3339;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /ledger/trial-balance?as_of=…: one section per currency.
 */
final class TrialBalanceController
{
    public function __invoke(Request $request, TrialBalance $trialBalance): JsonResponse
    {
        $request->validate(['as_of' => ['sometimes', ...Rfc3339::rules()]]);
        $asOf = $request->filled('as_of') ? Rfc3339::parse($request->string('as_of')->value()) : null;

        $sections = [];

        foreach ($trialBalance($asOf) as $code => $section) {
            $currency = Currency::from($code);
            $decimal = static fn (int $minor): string => Money::of($minor, $currency)->toDecimal();

            $sections[] = [
                'currency' => $code,
                'accounts' => array_map(static fn (array $account): array => [
                    'id' => $account['id'],
                    'code' => $account['code'],
                    'name' => $account['name'],
                    'type' => $account['type']->value,
                    'debits' => $decimal($account['debits']),
                    'credits' => $decimal($account['credits']),
                ], $section['accounts']),
                'total_debits' => $decimal($section['total_debits']),
                'total_credits' => $decimal($section['total_credits']),
                'balanced' => $section['balanced'],
            ];
        }

        return new JsonResponse(['data' => ['as_of' => $asOf?->toIso8601String(), 'currencies' => $sections]]);
    }
}
