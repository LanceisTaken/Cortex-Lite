<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGameRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:255'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['playing', 'backlog', 'completed', 'dropped'])],
            'playtime_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'last_played_at' => ['sometimes', 'nullable', 'date'],
            'steam_app_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cover_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ];
    }
}
