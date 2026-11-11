<?php

namespace App\Http\Controllers;

use App\Services\ActionResult;
use App\Services\BedService;
use App\Services\LobbyState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BedController extends Controller
{
    // NOTE: Laravel passes route parameters in URL order, so the trip's {code} (from the /t/{code} prefix)
    // arrives first even though we do not use it: the middleware already resolved the trip from it.

    // Laravel builds BedService for us and passes it in (dependency injection, like Depends() in FastAPI).
    public function __construct(private BedService $beds) {}

    public function grab(Request $request, string $code, int $bedId): JsonResponse
    {
        return $this->respond($request, $this->beds->grab(
            $request->attributes->get('trip'), $request->attributes->get('member'), $bedId
        ));
    }

    public function confirm(Request $request, string $code, int $bedId): JsonResponse
    {
        return $this->respond($request, $this->beds->confirm(
            $request->attributes->get('trip'), $request->attributes->get('member'), $bedId
        ));
    }

    public function release(Request $request, string $code, int $bedId): JsonResponse
    {
        return $this->respond($request, $this->beds->release(
            $request->attributes->get('trip'), $request->attributes->get('member'), $bedId
        ));
    }

    public function giveUp(Request $request, string $code, int $bedId): JsonResponse
    {
        return $this->respond($request, $this->beds->giveUp(
            $request->attributes->get('trip'), $request->attributes->get('member'), $bedId
        ));
    }

    // Always send back the fresh lobby state too, even on failure, so the screen is right immediately
    // (e.g. the loser of a race instantly sees the bed turn red instead of waiting for the next poll).
    private function respond(Request $request, ActionResult $result): JsonResponse
    {
        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'state' => LobbyState::build($request->attributes->get('trip'), $request->attributes->get('member')),
        ], $result->status);
    }
}
