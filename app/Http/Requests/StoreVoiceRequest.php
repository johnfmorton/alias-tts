<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'audio' => ['required', 'file', 'mimes:wav,mp3,m4a,aac,ogg,flac', 'max:20480'], // 20 MB
            'seed' => ['nullable', 'integer'],
            'raw' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The voice_id may only contain letters, numbers, dots, dashes and underscores.',
            'audio.required' => 'A reference audio clip is required to create a voice.',
        ];
    }
}
