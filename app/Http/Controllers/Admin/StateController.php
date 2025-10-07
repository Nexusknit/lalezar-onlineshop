<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class StateController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:state.all')->only('all');
        $this->middleware('permission:state.store')->only('store');
        $this->middleware('permission:state.update')->only('update');
    }

    #[OA\Get(
        path: '/api/admin/states',
        operationId: 'adminStatesIndex',
        summary: 'List states',
        security: [['sanctum' => []]],
        tags: ['Admin - States'],
        parameters: [
            new OA\Parameter(
                name: 'search',
                description: 'Filter states by name, slug, or code',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'States retrieved',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/StateResource')
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $states = State::query()
            ->withCount('cities')
            ->when($request->filled('search'), static function ($query) use ($request): void {
                $term = $request->string('search');
                $query->where(static function ($query) use ($term): void {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json($states);
    }

    #[OA\Post(
        path: '/api/admin/states',
        operationId: 'adminStatesStore',
        summary: 'Create a state',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Tehran'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'code', type: 'string', example: 'THR', nullable: true),
                ]
            )
        ),
        tags: ['Admin - States'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'State created',
                content: new OA\JsonContent(ref: '#/components/schemas/StateResource')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:states,slug'],
            'code' => ['nullable', 'string', 'max:50', 'unique:states,code'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $state = State::query()->create($data);

        return response()->json($state, 201);
    }

    #[OA\Patch(
        path: '/api/admin/states/{state}',
        operationId: 'adminStatesUpdate',
        summary: 'Update a state',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'code', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Admin - States'],
        parameters: [
            new OA\Parameter(
                name: 'state',
                description: 'State ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'State updated',
                content: new OA\JsonContent(ref: '#/components/schemas/StateResource')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, State $state): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('states', 'slug')->ignore($state->id),
            ],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('states', 'code')->ignore($state->id),
            ],
        ]);

        if (array_key_exists('name', $data) && ! array_key_exists('slug', $data)) {
            $data['slug'] = Str::slug($data['name']);
        }

        $state->fill($data)->save();

        return response()->json($state->fresh()->loadCount('cities'));
    }
}
