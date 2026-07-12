<?php

namespace App\Http\Requests;

use App\Rules\AudioOnlyUpload;
use App\Services\Tts\ModelCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            // The engine this voice generates with (defaults to classic chatterbox).
            'model' => ['nullable', 'string', Rule::in(ModelCatalog::keys())],
            // A built-in voice, for engines that ship them (turbo) — the voice
            // then needs no reference clip.
            'preset_voice' => ['nullable', 'string', Rule::in(ModelCatalog::presetVoices('chatterbox-turbo')), 'prohibited_unless:model,chatterbox-turbo'],
            // Optional: a voice with no reference clip uses Chatterbox's native voice.
            'audio' => ['nullable', 'file', 'mimes:wav,mp3,m4a,aac,ogg,flac', 'max:20480', new AudioOnlyUpload], // 20 MB
            'seed' => ['nullable', 'integer'],
            'raw' => ['sometimes', 'boolean'],
            // Clean up the reference clip (denoise + enhance) before storing.
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
        ];
    }
}
