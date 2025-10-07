<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:user.all')->only('all');
        $this->middleware('permission:user.store')->only('store');
        $this->middleware('permission:user.update')->only('update');
        $this->middleware('permission:user.accessibility')->only('accessibility');
        $this->middleware('permission:user.login')->only('loginAsUser');
    }

    #[OA\Get(
        path: '/api/admin/users',
        operationId: 'adminUsersIndex',
        summary: 'List users',
        security: [['sanctum' => []]],
        tags: ['Admin - Users'],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                description: 'Items per page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', example: 15)
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Filter by name or email',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Users retrieved', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $users = User::query()
            ->with(['roles', 'permissions'])
            ->when($request->filled('search'), static function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(static function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($users);
    }

    #[OA\Post(
        path: '/api/admin/users',
        operationId: 'adminUsersStore',
        summary: 'Create a user',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Admin User'),
                    new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                    new OA\Property(property: 'password', type: 'string', example: 'secret123'),
                ]
            )
        ),
        tags: ['Admin - Users'],
        responses: [
            new OA\Response(response: 201, description: 'User created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return response()->json($user->fresh()->load(['roles', 'permissions']), 201);
    }

    #[OA\Patch(
        path: '/api/admin/users/{user}',
        operationId: 'adminUsersUpdate',
        summary: 'Update a user',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                    new OA\Property(property: 'password', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Admin - Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (array_key_exists('password', $data) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->fill($data)->save();

        return response()->json($user->fresh()->load(['roles', 'permissions']));
    }

    #[OA\Post(
        path: '/api/admin/users/{user}/accessibility',
        operationId: 'adminUsersAccessibility',
        summary: 'Sync user roles and permissions',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'integer')),
                ]
            )
        ),
        tags: ['Admin - Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Accessibility updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function accessibility(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        if (array_key_exists('roles', $data)) {
            $user->roles()->sync($data['roles']);
        }

        if (array_key_exists('permissions', $data)) {
            $user->permissions()->sync($data['permissions']);
        }

        return response()->json($user->fresh()->load(['roles', 'permissions']));
    }

    #[OA\Post(
        path: '/api/admin/users/{user}/impersonate',
        operationId: 'adminUsersImpersonate',
        summary: 'Generate impersonation token for a user',
        security: [['sanctum' => []]],
        tags: ['Admin - Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Token generated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function loginAsUser(User $user): JsonResponse
    {
        $token = $user->createToken('impersonation-token', ['*'])->plainTextToken;

        return response()->json([
            'message' => 'Impersonation started for the requested user.',
            'user' => $user->load(['roles', 'permissions']),
            'token' => $token,
        ]);
    }
}
