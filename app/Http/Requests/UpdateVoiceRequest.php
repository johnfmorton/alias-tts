<?php

namespace App\Http\Requests;

use App\Rules\AudioOnlyUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $voice = $this->route('voice');

        // Slugs are unique per owner: renaming only conflicts inside this
        // voice's reachable set — the owner's other voices plus the shared
        // built-ins. A shared voice (null owner) sits in EVERY user's set, so
        // renaming one is checked against all voices.
        $unique = Rule::unique('voices', 'slug')->ignore($voice?->id);
        if ($voice?->user_id !== null) {
            $unique->where(fn ($q) => $q->where(
                fn ($qq) => $qq->whereNull('user_id')->orWhere('user_id', $voice->user_id)
            ));
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', $unique],
            'audio' => ['nullable', 'file', 'mimes:wav,mp3,m4a,aac,ogg,flac', 'max:20480', new AudioOnlyUpload], // 20 MB
            'seed' => ['nullable', 'integer'],
            // Chatterbox's native knobs — same ranges as the Studio bench.
            'exaggeration' => ['nullable', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['nullable', 'numeric', 'between:0.2,1'],
            'raw' => ['sometimes', 'boolean'],
            // Clean up the replacement clip (denoise + enhance) before storing.
            'enhance' => ['sometimes', 'boolean'],
            // A prepared-clip token + the A/B choice, when saving a previewed clip.
            'clip_token' => ['nullable', 'string', 'size:40', 'required_with:clip_choice'],
            'clip_choice' => ['nullable', 'in:original,enhanced', 'required_with:clip_token'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The voice_id may only contain letters, numbers, dots, dashes and underscores.',
            'slug.unique' => 'That voice_id is already in use by another voice.',
        ];
    }
}
