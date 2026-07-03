<?php

namespace App\Http\Requests;

/**
 * Validates POST /v1/projects. Reuses {@see TextToSpeechRequest}'s helpers
 * (voiceSettings/modelId/outputFormat/seed) and its ElevenLabs-shaped
 * failedValidation, but takes the voice in the body and an optional title, and
 * permits long-form text (projects are generated chunk-by-chunk later, so the
 * synchronous text ceiling doesn't apply — use the async cap).
 */
class CreateProjectRequest extends TextToSpeechRequest
{
    /**
     * Projects prefer the key owner's per-user project format (see
     * tts.project_output_format); the sync /v1/text-to-speech default stays
     * untouched so plugin audio is never affected. An explicit output_format
     * in the request still wins.
     */
    public function outputFormat(): string
    {
        return (string) ($this->input('output_format')
            ?: $this->query('output_format')
            ?: config('tts.project_output_format')
            ?: config('tts.default_output_format'));
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'voice_id' => ['required', 'string'],
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
            'model_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'output_format' => ['sometimes', 'string', 'max:32'],
            'seed' => ['sometimes', 'nullable', 'integer'],

            'voice_settings' => ['sometimes', 'array'],
            'voice_settings.stability' => ['sometimes', 'numeric', 'between:0,1'],
            'voice_settings.similarity_boost' => ['sometimes', 'numeric', 'between:0,1'],
            'voice_settings.style' => ['sometimes', 'numeric', 'between:0,1'],
            'voice_settings.use_speaker_boost' => ['sometimes', 'boolean'],
        ];
    }
}
