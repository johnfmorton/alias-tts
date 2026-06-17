<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class TextToSpeechRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_text_length')],
            'model_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'output_format' => ['sometimes', 'string', 'max:32'],
            'force_refresh' => ['sometimes', 'boolean'],
            'seed' => ['sometimes', 'nullable', 'integer'],

            'voice_settings' => ['sometimes', 'array'],
            'voice_settings.stability' => ['sometimes', 'numeric', 'between:0,1'],
            'voice_settings.similarity_boost' => ['sometimes', 'numeric', 'between:0,1'],
            'voice_settings.style' => ['sometimes', 'numeric', 'between:0,1'],
            'voice_settings.use_speaker_boost' => ['sometimes', 'boolean'],

            // ElevenLabs request-stitching fields: accepted, then ignored.
            'previous_text' => ['sometimes', 'nullable', 'string'],
            'next_text' => ['sometimes', 'nullable', 'string'],
            'previous_request_ids' => ['sometimes', 'array'],
            'voice_id' => ['sometimes', 'string'], // some clients echo it in the body
        ];
    }

    /**
     * Merge client-supplied voice_settings over the configured defaults.
     */
    public function voiceSettings(): array
    {
        $defaults = (array) config('tts.default_voice_settings');
        $provided = (array) $this->input('voice_settings', []);

        return [
            'stability' => (float) ($provided['stability'] ?? $defaults['stability']),
            'similarity_boost' => (float) ($provided['similarity_boost'] ?? $defaults['similarity_boost']),
            'style' => (float) ($provided['style'] ?? $defaults['style']),
            'use_speaker_boost' => (bool) ($provided['use_speaker_boost'] ?? $defaults['use_speaker_boost']),
        ];
    }

    public function modelId(): string
    {
        return (string) ($this->input('model_id') ?: config('tts.default_model_id'));
    }

    public function outputFormat(): string
    {
        return (string) ($this->input('output_format')
            ?: $this->query('output_format')
            ?: config('tts.default_output_format'));
    }

    public function seed(): ?int
    {
        $seed = $this->input('seed');

        return $seed === null || $seed === '' ? null : (int) $seed;
    }

    /**
     * Render validation errors in the ElevenLabs shape so clients surface a
     * clean message.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'detail' => ['message' => $validator->errors()->first(), 'status' => 422],
            ], 422)
        );
    }
}
