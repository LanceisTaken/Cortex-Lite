<?php

namespace App\Http\Controllers;

use App\Models\Cpu;
use App\Models\Gpu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareController extends Controller
{
    public function gpus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Gpu::query()->select(Gpu::RESPONSE_FIELDS);

        if (! empty($validated['search'])) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $validated['search']);
            $query->whereRaw("name like ? escape '!'", ["%{$search}%"]);
        }

        return response()->json($query->orderByDesc('g3d_mark')->limit(20)->get());
    }

    public function cpus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Cpu::query()->select(Cpu::RESPONSE_FIELDS);

        if (! empty($validated['search'])) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $validated['search']);
            $query->whereRaw("name like ? escape '!'", ["%{$search}%"]);
        }

        return response()->json($query->orderByDesc('single_thread_mark')->limit(20)->get());
    }
}
