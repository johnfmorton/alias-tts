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
            'text' => ['required', 'string', 'max:'.$this->maxTextLength()],
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
     * Text length cap for this request. The async jobs endpoint generates in a
     * background worker (bounded by tts.async_timeout, not the ~300s synchronous
     * request budget), so it permits far longer text than the sync endpoint.
     */
    private function maxTextLength(): int
    {
        return $this->routeIs('tts.jobs.queue')
            ? (int) config('tts.max_async_text_length')
            : (int) config('tts.max_text_length');
    }

    /**
     * The voice_settings keys the client explicitly sent. Only these override
     * the voice's saved defaults and the configured defaults — the layering
     * itself is done by {@see \App\Services\Tts\VoiceSettingsResolver}. Empty
     * when the client sent no voice_settings.
     *
     * @return array<string, mixed>
     */
    public function voiceSettingOverrides(): array
    {
        return (array) $this->input('voice_settings', []);
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
