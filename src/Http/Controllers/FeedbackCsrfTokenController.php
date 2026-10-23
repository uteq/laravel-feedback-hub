<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Fresh CSRF token for the widget's one retry after a 419. Behind the auth middleware, so a signed-out user gets a 401 instead of a token.
 */
final class FeedbackCsrfTokenController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['token' => csrf_token()]);
    }
}
