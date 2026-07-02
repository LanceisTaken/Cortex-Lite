<?php

namespace App\Http\Requests\Steam;

use Illuminate\Foundation\Http\FormRequest;

class ConnectVanityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vanity' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?:[A-Za-z0-9_-]{1,32}|https?:\/\/steamcommunity\.com\/id\/[A-Za-z0-9_-]{1,32}\/?)$/',
            ],
        ];
    }
}
