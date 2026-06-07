<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:product.all')->only('index');
        $this->middleware('permission:product.update')->only('store');
    }

    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->string('search'));
        $tags = Tag::query()
            ->when($term !== '', static fn ($query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(50)
            ->get();

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('tags', 'slug')],
            'color' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
        ]);
        $data['slug'] = $data['slug'] ?? (Str::slug($data['name']) ?: 'tag-'.Str::lower(Str::random(8)));
        $data['creator_id'] = $request->user()->id;

        return response()->json(Tag::query()->create($data), 201);
    }
}
