<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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

    public function system_clone()
    {
        $result = $this->genMax->getSystemVoicesClone();

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

        return response()->json($result['data'], $result['status']);
    }

    public function clone(Request $request)
    {
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
        $multipart[] = [
            'name' => 'file',
            'file' => fopen($file->getRealPath(), 'r'),
            'filename' => $file->getClientOriginalName(),
        ];

        $fields = ['voice_name', 'language_tag', 'gender', 'need_noise_reduction', 'preview_text'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $multipart[] = ['name' => $field, 'value' => $request->input($field)];
            }
        }

        $result = $this->genMax->cloneVoice($multipart);

        return response()->json($result['data'], $result['status']);
    }

    public function delete(Request $request, string $id)
    {
        $result = $this->genMax->deleteVoice($id);

        return response()->json($result['data'], $result['status']);
    }
}
