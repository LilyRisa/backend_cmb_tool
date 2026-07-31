<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\VoiceClone;
use App\Services\GenMaxService;
use Illuminate\Http\Request;

class ToolVoiceController extends Controller
{
    protected GenMaxService $genMax;

    public function __construct(GenMaxService $genMax)
    {
        $this->genMax = $genMax;
    }

    public function models(Request $request)
    {
        $result = $this->genMax->getModels($request->get('provider'));

        return response()->json($result['data'], $result['status']);
    }

    public function system_clone(Request $request)
    {
        $result = $this->genMax->getSystemVoicesClone();

        // Same account-wide-list problem as clonedVoices() below: this hits
        // GET /v1/minimax/voices/ on the provider, which returns the same
        // unfiltered, account-wide cloned-voice list as getClonedVoices()'s
        // GET /v1/minimax/voices (they differ only by a trailing slash). Must
        // be scoped to the caller's own voices for the same reason.
        if ($result['success']) {
            $result['data'] = $this->filterVoicesToOwner($result['data'] ?? null, $request->user()->id);
        }

        return response()->json($result['data'], $result['status']);
    }

    public function systemVoices(Request $request)
    {
        $filters = $request->only(['page', 'page_size', 'search', 'gender', 'language', 'accent', 'age', 'use_cases']);

        $result = $this->genMax->getSystemVoices($filters);

        return response()->json($result['data'], $result['status']);
    }

    public function clonedVoices(Request $request)
    {
        $result = $this->genMax->getClonedVoices();

        // All users share one GenMax provider account (a single admin-configured
        // API key), so the raw provider response is account-wide and contains
        // every user's cloned voices. Filter it down to only the voices this
        // caller owns per our local VoiceClone ownership table before it ever
        // leaves this endpoint — otherwise any authenticated user could
        // enumerate other users' cloned voices.
        if ($result['success']) {
            $result['data'] = $this->filterVoicesToOwner($result['data'] ?? null, $request->user()->id);
        }

        return response()->json($result['data'], $result['status']);
    }

    /**
     * Scope a GenMax "cloned voices" response down to only the voices the
     * given user owns per our local VoiceClone table. Fails CLOSED: if $data
     * isn't shaped the way we expect (missing/non-array 'voices' key, or not
     * an array at all), we return an empty voice list rather than risk
     * falling through and returning the raw, unfiltered, account-wide
     * response — a shape we haven't recognized is not a safe default to
     * disclose.
     */
    private function filterVoicesToOwner($data, int $userId): array
    {
        if (!is_array($data) || !isset($data['voices']) || !is_array($data['voices'])) {
            return ['voices' => []];
        }

        $ownedVoiceIds = VoiceClone::where('user_id', $userId)
            ->pluck('provider_voice_id')
            ->all();

        $data['voices'] = array_values(array_filter(
            $data['voices'],
            function ($voice) use ($ownedVoiceIds) {
                $voiceId = is_array($voice) ? ($voice['voice_id'] ?? $voice['id'] ?? null) : null;

                return $voiceId !== null && in_array($voiceId, $ownedVoiceIds, true);
            }
        ));

        return $data;
    }

    public function clone(Request $request)
    {
        if (!$request->user()->isPremium()) {
            return response()->json([
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a,ogg,flac,mp4,webm',
            'voice_name' => 'required|string|max:255',
            'language_tag' => 'nullable|string',
            'gender' => 'nullable|string|in:Male,Female',
            'need_noise_reduction' => 'nullable|boolean',
            'preview_text' => 'nullable|string|max:200',
        ]);

        $multipart = [];

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        $multipart[] = [
            'name' => 'file',
            'file' => $handle,
            'filename' => $file->getClientOriginalName(),
        ];

        $fields = ['voice_name', 'language_tag', 'gender', 'need_noise_reduction', 'preview_text'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $multipart[] = ['name' => $field, 'value' => $request->input($field)];
            }
        }

        try {
            $result = $this->genMax->cloneVoice($multipart);
        } finally {
            // Http::attach() hands the raw resource off to Guzzle, which wraps it
            // in a PSR-7 stream that closes it on destruction — but Guzzle's
            // client/handler stack holds closures that can form reference
            // cycles, so that destruction isn't guaranteed to happen right after
            // the request completes. Close explicitly to avoid leaking file
            // descriptors on high-volume voice cloning.
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($result['success']) {
            $voiceId = $result['data']['voice_id'] ?? null;

            if ($voiceId) {
                // provider_voice_id is unique on voice_clones. If the provider
                // ever returns a voice_id that already has a row (retry,
                // provider-side dedup), a bare create() would throw an
                // unhandled QueryException after the clone already succeeded,
                // leaving the voice unmanageable. updateOrCreate() instead
                // reassigns/updates the existing row (the caller who just
                // successfully cloned it is the current owner of record).
                VoiceClone::updateOrCreate(
                    ['provider_voice_id' => $voiceId],
                    ['user_id' => $request->user()->id, 'voice_name' => $request->input('voice_name')]
                );
            }
        }

        return response()->json($result['data'], $result['status']);
    }

    public function delete(Request $request, string $id)
    {
        // Scope deletion to voices this caller actually cloned. All users share
        // one GenMax provider account, so without this check any caller could
        // pass through an arbitrary provider voice ID and delete another
        // user's cloned voice (cross-tenant IDOR). Return a plain 404 rather
        // than a 403 so we don't leak whether the voice exists at all for
        // another user.
        $voiceClone = VoiceClone::where('provider_voice_id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$voiceClone) {
            return response()->json(['error' => 'Không tìm thấy'], 404);
        }

        $result = $this->genMax->deleteVoice($id);

        if ($result['success']) {
            $voiceClone->delete();
        }

        return response()->json($result['data'], $result['status']);
    }
}
