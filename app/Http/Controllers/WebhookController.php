<?php

namespace App\Http\Controllers;

use App\Services\WebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The public address booking sites call: POST /webhooks/{provider}. All the thinking is in WebhookReceiver. */
class WebhookController extends Controller
{
    public function __construct(private WebhookReceiver $receiver) {}

    public function receive(Request $request, string $provider): JsonResponse
    {
        // getContent() is the raw body, byte for byte: the signature is computed over exactly these bytes.
        $result = $this->receiver->handle(
            $provider,
            $request->getContent(),
            $request->header('X-Lobby-Timestamp'),
            $request->header('X-Lobby-Signature'),
        );

        return response()->json($result['body'], $result['status'])->header('Cache-Control', 'no-store');
    }
}
