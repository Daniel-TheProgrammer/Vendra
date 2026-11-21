<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\ResetPassword;
use App\Modules\Identity\Application\Actions\SendPasswordResetLink;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class PasswordController
{
    /**
     * Same answer whether or not the address has an account.
     */
    public function forgot(ForgotPasswordRequest $request, SendPasswordResetLink $send): JsonResponse
    {
        $send($request->email());

        return new JsonResponse(
            ['data' => ['message' => 'If an account exists for this address, a reset link is on its way.']],
            202,
        );
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $reset): Response
    {
        $reset($request->toCommand());

        return response()->noContent();
    }
}
