<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $tickets = $request->user()
            ->tickets()
            ->withCount([
                'chats',
            ])
            ->latest()
            ->paginate($perPage);

        return response()->json($tickets);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'string', 'max:100'],
            'model_type' => ['nullable', 'string', 'max:255'],
            'model_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['model_type']) xor ! empty($data['model_id'])) {
            abort(422, 'Both model_type and model_id are required when referencing another entity.');
        }

        $ticket = $request->user()->tickets()->create([
            'type' => $data['type'] ?? 'support',
            'status' => 'open',
            'subject' => $data['subject'],
            'priority' => $data['priority'] ?? 'normal',
            'description' => $data['description'] ?? null,
            'model_type' => $data['model_type'] ?? null,
            'model_id' => $data['model_id'] ?? null,
        ]);

        return response()->json($ticket->fresh(), 201);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);

        $ticket->load([
            'chats' => static function ($query): void {
                $query->with('user:id,name')
                    ->latest();
            },
        ])->loadCount('chats');

        $ticket->setRelation('chats', $ticket->chats->take(50)->reverse()->values());

        return response()->json($ticket);
    }

    public function sendMessage(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);

        $data = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $chat = $ticket->chats()->create([
            'user_id' => $request->user()->id,
            'message' => $data['message'],
            'is_internal' => false,
        ]);

        return response()->json($chat->fresh()->load('user:id,name'), 201);
    }

    protected function authorizeTicket(Request $request, Ticket $ticket): void
    {
        abort_if($ticket->user_id !== $request->user()->id, 404, 'Ticket not found.');
    }
}
