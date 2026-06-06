<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:coupon.all')->only(['index', 'show']);
        $this->middleware('permission:coupon.store')->only('store');
        $this->middleware('permission:coupon.update')->only('update');
        $this->middleware('permission:coupon.delete')->only('destroy');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));

        $coupons = Coupon::query()
            ->withCount('usages')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = trim((string) $request->string('search'));
                $query->where(function ($query) use ($term): void {
                    $query->where('code', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate($perPage);

        return response()->json($coupons);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return response()->json($coupon->loadCount('usages'));
    }

    public function store(Request $request): JsonResponse
    {
        $coupon = Coupon::query()->create($this->validatedData($request));

        return response()->json($coupon->fresh()->loadCount('usages'), 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $coupon->fill($this->validatedData($request, $coupon))->save();

        return response()->json($coupon->fresh()->loadCount('usages'));
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted successfully.']);
    }

    private function validatedData(Request $request, ?Coupon $coupon = null): array
    {
        $codeRule = Rule::unique('coupons', 'code');
        if ($coupon) {
            $codeRule->ignore($coupon->id);
        }

        $rules = [
            'code' => [$coupon ? 'sometimes' : 'required', 'string', 'max:64', $codeRule],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => [$coupon ? 'sometimes' : 'required', Rule::in([Coupon::TYPE_FIXED, Coupon::TYPE_PERCENT])],
            'discount_value' => [$coupon ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'draft'])],
            'meta' => ['nullable', 'array'],
        ];

        $data = $request->validate($rules);
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        return $data;
    }
}
