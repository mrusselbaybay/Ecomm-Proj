<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailOnboardingController extends Controller
{
    public function __construct(private readonly PasswordResetController $verification) {}

    public function start(Request $request): JsonResponse
    {
        return $this->verification->sendSignupCode($request);
    }

    public function verify(Request $request): JsonResponse
    {
        return $this->verification->verifySignupCode($request);
    }
}
