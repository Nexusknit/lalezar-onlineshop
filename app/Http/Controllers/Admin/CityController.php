<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CityController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:city.all')->only('all');
        $this->middleware('permission:city.store')->only('store');
        $this->middleware('permission:city.update')->only('update');
    }

    #[OA\Get(
        path: '/api/admin/cities',
        operationId: 'adminCitiesIndex',
        summary: 'List cities',
        security: [['sanctum' => []]],
        tags: ['Admin - Cities'],
        parameters: [
            new OA\Parameter(
                name: 'state_id',
                description: 'Filter cities by state ID',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cities retrieved',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/CityResource')
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $cities = City::query()
            ->with('state:id,name,slug')
            ->when($request->filled('state_id'), static function ($query) use ($request): void {
                $query->where('state_id', $request->integer('state_id'));
            })
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

        return response()->json($cities);
    }

    #[OA\Post(
        path: '/api/admin/cities',
        operationId: 'adminCitiesStore',
        summary: 'Create a city',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['state_id', 'name'],
                properties: [
                    new OA\Property(property: 'state_id', type: 'integer', example: 1),
                    new OA\Property(property: 'name', type: 'string', example: 'Karaj'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'code', type: 'string', nullable: true),
                    new OA\Property(property: 'is_capital', type: 'boolean', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Cities'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'City created',
                content: new OA\JsonContent(ref: '#/components/schemas/CityResource')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state_id' => ['required', Rule::exists('states', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:cities,slug'],
            'code' => ['nullable', 'string', 'max:50'],
            'is_capital' => ['nullable', 'boolean'],
        ]);

        $state = State::query()->find($data['state_id']);
        $stateSlug = $state?->slug ?? ($state ? Str::slug($state->name) : null);

        $data['slug'] = $data['slug'] ?? ($stateSlug
            ? Str::slug($stateSlug.' '.$data['name'])
            : Str::slug($data['name']));
        $data['is_capital'] = (bool) ($data['is_capital'] ?? false);

        $city = City::query()->create($data);

        return response()->json($city->load('state:id,name,slug'), 201);
    }

    #[OA\Patch(
        path: '/api/admin/cities/{city}',
        operationId: 'adminCitiesUpdate',
        summary: 'Update a city',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'state_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'code', type: 'string', nullable: true),
                    new OA\Property(property: 'is_capital', type: 'boolean', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Cities'],
        parameters: [
            new OA\Parameter(
                name: 'city',
                description: 'City ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'City updated',
                content: new OA\JsonContent(ref: '#/components/schemas/CityResource')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, City $city): JsonResponse
    {
        $data = $request->validate([
            'state_id' => ['sometimes', Rule::exists('states', 'id')],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('cities', 'slug')->ignore($city->id),
            ],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_capital' => ['sometimes', 'boolean'],
        ]);

        $state = null;
        if (array_key_exists('state_id', $data)) {
            $state = State::query()->find($data['state_id']);
        } else {
            $city->loadMissing('state');
            $state = $city->state;
        }

        $stateSlug = $state?->slug ?? ($state ? Str::slug($state->name) : null);

        if (array_key_exists('name', $data) && ! array_key_exists('slug', $data)) {
            $data['slug'] = $stateSlug
                ? Str::slug($stateSlug.' '.$data['name'])
                : Str::slug($data['name']);
        }

        $city->fill($data)->save();

        return response()->json($city->fresh()->load('state:id,name,slug'));
    }
}
