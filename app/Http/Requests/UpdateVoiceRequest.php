<?php

namespace App\Http\Requests;

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
        $voiceId = $this->route('voice')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('voices', 'slug')->ignore($voiceId)],
            'audio' => ['nullable', 'file', 'mimes:wav,mp3,m4a,aac,ogg,flac', 'max:20480'], // 20 MB
            'seed' => ['nullable', 'integer'],
            'raw' => ['sometimes', 'boolean'],
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
