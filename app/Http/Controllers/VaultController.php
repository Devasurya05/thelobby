<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadVaultFileRequest;
use App\Services\ActionResult;
use App\Services\VaultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The vault page and its actions. Role rules live in VaultService; this class only passes things along. */
class VaultController extends Controller
{
    public function __construct(private VaultService $vault) {}

    public function index(Request $request): View
    {
        $trip = $request->attributes->get('trip');

        return view('vault', [
            'trip' => $trip,
            'me' => $request->attributes->get('member'),
            // Only files that still exist. Newest first.
            'files' => $trip->vaultFiles()->whereNull('deleted_at')->with('uploadedBy:id,name')->orderByDesc('id')->get(),
            'maxMb' => (int) (config('lobby.vault.max_kb') / 1024),
        ]);
    }

    public function upload(UploadVaultFileRequest $request): RedirectResponse
    {
        return $this->back($request, $this->vault->upload(
            $request->attributes->get('trip'), $request->attributes->get('member'), $request->file('file')
        ));
    }

    // Route params arrive in URL order: {code} (from the prefix) first, then {fileId}.
    public function open(Request $request, string $code, int $fileId): RedirectResponse
    {
        $result = $this->vault->openLink($request->attributes->get('trip'), $request->attributes->get('member'), $fileId);

        if (! $result->ok) {
            return $this->back($request, $result);
        }

        // Send the browser to S3 with a link that works for 60 seconds. no-store: never cached by the browser or a proxy.
        return redirect()->away($result->value)->header('Cache-Control', 'no-store');
    }

    public function delete(Request $request, string $code, int $fileId): RedirectResponse
    {
        return $this->back($request, $this->vault->delete(
            $request->attributes->get('trip'), $request->attributes->get('member'), $fileId
        ));
    }

    public function endTrip(Request $request): RedirectResponse
    {
        return $this->back($request, $this->vault->endTrip(
            $request->attributes->get('trip'), $request->attributes->get('member')
        ));
    }

    private function back(Request $request, ActionResult $result): RedirectResponse
    {
        return redirect()->route('vault.index', $request->attributes->get('trip')->join_code)
            ->with($result->ok ? 'status' : 'problem', $result->message);
    }
}
