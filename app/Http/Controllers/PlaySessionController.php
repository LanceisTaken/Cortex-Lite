<?php

namespace App\Http\Controllers;

use App\Actions\PlaySessions\EndPlaySessionAction;
use App\Actions\PlaySessions\StartPlaySessionAction;
use App\Exceptions\PlaySessionAlreadyActiveException;
use App\Exceptions\PlaySessionAlreadyEndedException;
use App\Http\Requests\PlaySessions\StartPlaySessionRequest;
use App\Models\PlaySession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaySessionController extends Controller
{
    public function start(StartPlaySessionRequest $request, StartPlaySessionAction $action): JsonResponse
    {
        try {
            $session = $action->execute($request->user(), (int) $request->validated('game_id'));
        } catch (ModelNotFoundException) {
            return response()->json(null, 404);
        } catch (PlaySessionAlreadyActiveException) {
            return response()->json([
                'error_code' => 'play_session_already_active',
                'message' => 'You already have an active play session.',
            ], 409);
        }

        $session->load(['game' => fn ($query) => $query->select(['id', 'title', 'cover_url', 'steam_app_id'])]);

        return response()->json($this->serialize($session), 201);
    }

    public function end(Request $request, PlaySession $session, EndPlaySessionAction $action): JsonResponse
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(null, 404);
        }

        try {
            $ended = $action->execute($session);
        } catch (PlaySessionAlreadyEndedException) {
            return response()->json([
                'error_code' => 'play_session_already_ended',
                'message' => 'This play session has already ended.',
            ], 409);
        }

        return response()->json($ended->only(PlaySession::RESPONSE_FIELDS));
    }

    public function active(Request $request): JsonResponse
    {
        $session = $request->user()->playSessions()
            ->whereNull('ended_at')
            ->with(['game' => fn ($query) => $query->select(['id', 'title', 'cover_url', 'steam_app_id'])])
            ->orderByDesc('started_at')
            ->first();

        return response()->json([
            'data' => $session === null ? null : $this->serialize($session),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $sessions = $request->user()->playSessions()
            ->whereNotNull('ended_at')
            ->with(['game' => fn ($query) => $query->select(['id', 'title', 'cover_url', 'steam_app_id'])])
            ->orderByDesc('ended_at')
            ->paginate(15);

        $trackedTotals = $request->user()->playSessions()
            ->whereNotNull('ended_at')
            ->selectRaw('game_id, sum(duration_seconds) as duration_seconds_total')
            ->groupBy('game_id')
            ->pluck('duration_seconds_total', 'game_id');

        return response()->json([
            'data' => collect($sessions->items())
                ->map(fn (PlaySession $session) => $this->serialize($session, $trackedTotals))
                ->all(),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    private function serialize(PlaySession $session, $trackedTotals = null): array
    {
        $game = $session->game?->only(['id', 'title', 'cover_url', 'steam_app_id']);

        if ($game !== null && $trackedTotals !== null) {
            $game['tracked_duration_seconds_total'] = (int) ($trackedTotals[$session->game_id] ?? 0);
        }

        return [
            ...$session->only(PlaySession::RESPONSE_FIELDS),
            'game' => $game,
        ];
    }
}
