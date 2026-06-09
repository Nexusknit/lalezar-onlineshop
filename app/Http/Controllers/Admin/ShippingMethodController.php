<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShippingMethodController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:shipping.all')->only('index');
        $this->middleware('permission:shipping.store')->only('store');
        $this->middleware('permission:shipping.update')->only('update');
    }

    public function index(): JsonResponse
    {
        return response()->json(ShippingMethod::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $method = ShippingMethod::query()->create($this->validated($request));

        return response()->json($method, 201);
    }

    public function update(Request $request, ShippingMethod $shippingMethod): JsonResponse
    {
        $shippingMethod->update($this->validated($request, $shippingMethod));

        return response()->json($shippingMethod->fresh());
    }

    private function validated(Request $request, ?ShippingMethod $method = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'alpha_dash', 'max:60', Rule::unique('shipping_methods')->ignore($method)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'cost_per_kg' => ['nullable', 'numeric', 'min:0'],
            'max_weight_grams' => ['nullable', 'integer', 'min:1'],
            'free_threshold' => ['nullable', 'numeric', 'min:0'],
            'state_ids' => ['nullable', 'array'],
            'state_ids.*' => ['integer', 'exists:states,id'],
            'city_ids' => ['nullable', 'array'],
            'city_ids.*' => ['integer', 'exists:cities,id'],
            'estimated_days_min' => ['nullable', 'integer', 'min:0', 'max:365'],
            'estimated_days_max' => ['nullable', 'integer', 'gte:estimated_days_min', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }
}
