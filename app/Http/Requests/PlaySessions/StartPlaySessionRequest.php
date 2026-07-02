<?php

namespace App\Http\Requests\PlaySessions;

use Illuminate\Foundation\Http\FormRequest;

class StartPlaySessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'game_id' => ['required', 'integer', 'exists:games,id'],
        ];
    }
}
