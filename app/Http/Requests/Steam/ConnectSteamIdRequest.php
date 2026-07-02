<?php

namespace App\Http\Requests\Steam;

use Illuminate\Foundation\Http\FormRequest;

class ConnectSteamIdRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'steam_id' => [
                'required',
                'string',
                'size:17',
                'regex:/^7656119\d{10}$/',
            ],
        ];
    }
}
