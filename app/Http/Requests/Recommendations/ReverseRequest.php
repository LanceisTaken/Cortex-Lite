<?php

namespace App\Http\Requests\Recommendations;

use App\Models\SettingPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReverseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'gpu_id' => ['required', 'integer', 'exists:gpus,id'],
            'cpu_id' => ['required', 'integer', 'exists:cpus,id'],
            'ram_gb' => ['required', 'integer', 'min:1', 'max:512'],
            'goal' => ['required', Rule::in(SettingPreset::GOALS)],
            'current_settings' => ['required', 'array'],
        ];
    }
}
