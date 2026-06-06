<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class TicketController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:ticket.all')->only(['index', 'show']);
        $this->middleware('permission:ticket.store')->only('store');
        $this->middleware('permission:ticket.update')->only('update');
        $this->middleware('permission:ticket.sendMessage')->only('sendMessage');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));

        $tickets = Ticket::query()
            ->with(['user:id,name,phone,email'])
            ->withCount('chats')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = trim((string) $request->string('search'));
                $query->where(function ($query) use ($term): void {
                    $query->where('subject', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"));
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($tickets);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json(
            $ticket->load([
                'user:id,name,phone,email',
                'chats' => fn ($query) => $query->with('user:id,name')->oldest(),
            ])
        );
    }

    #[OA\Post(
        path: '/api/admin/tickets',
        operationId: 'adminTicketsStore',
        summary: 'Create ticket for a user',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id', 'subject'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer'),
                    new OA\Property(property: 'subject', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'type', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'priority', type: 'string', nullable: true),
                    new OA\Property(property: 'model_type', type: 'string', nullable: true),
                    new OA\Property(property: 'model_id', type: 'integer', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Tickets'],
        responses: [
            new OA\Response(response: 201, description: 'Ticket created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'model_type' => ['nullable', 'string', 'max:255'],
            'model_id' => ['nullable', 'integer'],
        ]);

        $ticket = Ticket::query()->create($data);

        return response()->json($ticket->fresh()->load('user'), 201);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'pending', 'answered', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'type' => ['sometimes', 'string', 'max:100'],
        ]);

        if (($data['status'] ?? null) === 'resolved') {
            $data['resolved_at'] = now();
        } elseif (isset($data['status']) && $data['status'] !== 'resolved') {
            $data['resolved_at'] = null;
        }

        $ticket->update($data);

        return response()->json($ticket->fresh()->load(['user', 'chats.user']));
    }

    #[OA\Post(
        path: '/api/admin/tickets/{ticket}/messages',
        operationId: 'adminTicketsSendMessage',
        summary: 'Send message to ticket',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'is_internal', type: 'boolean', nullable: true),
                    new OA\Property(property: 'user_id', type: 'integer', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Tickets'],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function sendMessage(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string'],
            'is_internal' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $data['user_id'] = $data['user_id'] ?? $request->user()?->id;

        $chat = $ticket->chats()->create([
            'user_id' => $data['user_id'],
            'message' => $data['message'],
            'is_internal' => (bool) ($data['is_internal'] ?? false),
        ]);

        if (! (bool) ($data['is_internal'] ?? false)) {
            $ticket->update([
                'status' => 'answered',
                'resolved_at' => null,
            ]);
        }

        return response()->json($chat->fresh()->load('user'), 201);
    }
}
