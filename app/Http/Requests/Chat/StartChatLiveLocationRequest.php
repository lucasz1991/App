<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatLiveLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartChatLiveLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'duration_minutes' => ['required', 'integer', Rule::in(ChatLiveLocation::ALLOWED_DURATIONS)],
            'reply_to_message_id' => ['nullable', 'integer', 'min:1'],
            ...$this->positionRules(),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function positionRules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'between:0,50000'],
            'altitude' => ['nullable', 'numeric', 'between:-12000,100000'],
            'altitude_accuracy' => ['nullable', 'numeric', 'between:0,50000'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed' => ['nullable', 'numeric', 'between:0,500'],
        ];
    }
}
