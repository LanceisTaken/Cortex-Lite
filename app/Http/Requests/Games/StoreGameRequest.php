<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGameRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['playing', 'backlog', 'completed', 'dropped'])],
            'playtime_minutes' => ['nullable', 'integer', 'min:0'],
            'last_played_at' => ['nullable', 'date'],
            'steam_app_id' => ['nullable', 'integer', 'min:0'],
            'cover_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
