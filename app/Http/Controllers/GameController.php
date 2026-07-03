<?php

namespace App\Http\Controllers;

use App\Http\Requests\Games\StoreGameRequest;
use App\Http\Requests\Games\UpdateGameRequest;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:playing,backlog,completed,dropped'],
            'metadata_status' => ['nullable', 'in:pending,ok,missing'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:last_played_desc,title_asc,playtime_desc'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $request->user()->games()->select(Game::RESPONSE_FIELDS);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['metadata_status'])) {
            $query->where('metadata_status', $validated['metadata_status']);
        }

        if (! empty($validated['search'])) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $validated['search']);
            $query->whereRaw("title like ? escape '!'", ["%{$search}%"]);
        }

        match ($validated['sort'] ?? 'last_played_desc') {
            'title_asc' => $query->orderBy('title'),
            'playtime_desc' => $query->orderByDesc('playtime_minutes'),
            default => $query->orderByDesc('last_played_at'),
        };

        $games = $query->paginate(15);

        return response()->json([
            'data' => $games->items(),
            'meta' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
                'total' => $games->total(),
            ],
        ]);
    }

    public function store(StoreGameRequest $request): JsonResponse
    {
        $game = $request->user()->games()->create([
            ...$request->validated(),
            'source' => 'manual',
            'metadata_status' => 'missing',
        ]);

        return response()->json($game->only(Game::RESPONSE_FIELDS), 201);
    }

    public function update(UpdateGameRequest $request, Game $game): JsonResponse
    {
        $game = $request->user()->games()->findOrFail($game->id);
        $game->update($request->validated());

        return response()->json($game->only(Game::RESPONSE_FIELDS));
    }

    public function destroy(Request $request, Game $game): JsonResponse
    {
        $game = $request->user()->games()->findOrFail($game->id);
        $game->delete();

        return response()->json(null, 204);
    }
}
