<?php

namespace App\Http\Requests;

use App\Http\Controllers\OpenAiSpeechController;
use App\Support\OpenAiError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates the OpenAI `POST /v1/audio/speech` body. Field names mirror OpenAI's
 * so an OpenAI-SDK client works unchanged; the translation to Bespoken's internal
 * synthesis call happens in {@see OpenAiSpeechController}.
 */
class OpenAiSpeechRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'input' => ['required', 'string', 'max:'.(int) config('tts.max_text_length')],
            'voice' => ['required', 'string', 'max:128'],
            // OpenAI requires `model`, but Bespoken's configured provider decides
            // the model — accept any value and ignore it.
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            // Only the formats AudioConverter can produce today. opus/aac/flac are
            // valid OpenAI values but unsupported here (see OpenAiSpeechController).
            'response_format' => ['sometimes', 'string', 'in:mp3,wav,pcm'],
            // Accepted for OpenAI compatibility, not yet applied (no slot in the
            // provider / VoiceSettingsResolver) — like the ElevenLabs stitching
            // fields the /v1 surface already accepts-and-ignores.
            'speed' => ['sometimes', 'numeric', 'between:0.25,4'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'stream_format' => ['sometimes', 'string'],
        ];
    }

    public function text(): string
    {
        return (string) $this->input('input');
    }

    public function voiceName(): string
    {
        return (string) $this->input('voice');
    }

    public function responseFormat(): string
    {
        return strtolower((string) ($this->input('response_format') ?: 'mp3'));
    }

    /**
     * Render validation errors in the OpenAI shape, pointing `param` at the first
     * offending field so SDK clients surface it cleanly.
     */
    protected function failedValidation(Validator $validator): void
    {
        $field = $validator->errors()->keys()[0] ?? null;

        throw new HttpResponseException(
            OpenAiError::json($validator->errors()->first(), 400, param: $field)
        );
    }
}
