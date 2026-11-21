<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\VerifyEmail;
use App\Modules\Identity\Http\Concerns\ResolvesPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController
{
    use ResolvesPrincipal;

    public function verify(string $id, string $hash, VerifyEmail $verify): JsonResponse
    {
        $verify($id, $hash);

        return new JsonResponse(['data' => ['verified' => true]]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $this->principal($request);

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return new JsonResponse(['data' => ['message' => 'If the address is not yet verified, a new link is on its way.']], 202);
    }
}
