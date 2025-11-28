<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:brand.all')->only('all');
        $this->middleware('permission:brand.store')->only('store');
        $this->middleware('permission:brand.update')->only('update');
        $this->middleware('permission:brand.delete')->only('delete');
        $this->middleware('permission:brand.activate')->only('activate');
    }

    public function all(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $brands = Brand::query()
            ->with(['products:id'])
            ->when($request->filled('status'), static function ($query) use ($request) {
                $query->where('status', $request->string('status'));
            })
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json($brands);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:brands,slug'],
            'summary' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'email' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'creator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['creator_id'] = $data['creator_id'] ?? $request->user()?->id;
        $productIds = collect($data['product_ids'] ?? [])->unique()->values()->all();
        unset($data['product_ids']);

        $brand = Brand::query()->create($data);

        if (! empty($productIds)) {
            $brand->products()->sync($productIds);
        }

        return response()->json($brand->fresh()->load('products:id'), 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('brands', 'slug')->ignore($brand->id),
            ],
            'summary' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'email' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')],
        ]);

        $productIds = collect($data['product_ids'] ?? null)->unique()->values()->all();
        unset($data['product_ids']);

        if (! empty($data)) {
            $brand->fill($data)->save();
        }

        if ($productIds !== []) {
            $brand->products()->sync($productIds);
        } elseif ($request->has('product_ids')) {
            $brand->products()->sync([]);
        }

        return response()->json($brand->fresh()->load('products:id'));
    }

    public function delete(Brand $brand): JsonResponse
    {
        $brand->delete();

        return response()->json(['message' => 'Brand deleted.']);
    }

    public function activate(Brand $brand): JsonResponse
    {
        $brand->update(['status' => 'active']);

        return response()->json($brand->fresh());
    }
}
