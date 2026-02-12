<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $addresses = $request->user()
            ->addresses()
            ->with(['city.state'])
            ->latest()
            ->paginate($perPage);

        return response()->json($addresses);
    }

    public function show(Request $request, Address $address): JsonResponse
    {
        $address = $this->resolveAddress($request, $address);

        return response()->json($address->load(['city.state']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
            'label' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'street_line1' => ['required', 'string', 'max:255'],
            'street_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'building' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $address = $request->user()->addresses()->create($data);

        if (($data['is_default'] ?? false) === true) {
            $this->setDefaultAddress($request, $address);
        } elseif ($request->user()->addresses()->where('id', '!=', $address->id)->doesntExist()) {
            $address->update(['is_default' => true]);
        }

        return response()->json($address->fresh()->load(['city.state']), 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $address = $this->resolveAddress($request, $address);

        $data = $request->validate([
            'city_id' => ['sometimes', 'integer', Rule::exists('cities', 'id')],
            'label' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'street_line1' => ['sometimes', 'string', 'max:255'],
            'street_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'building' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $address->fill($data)->save();

        if (($data['is_default'] ?? null) === true) {
            $this->setDefaultAddress($request, $address);
        }

        return response()->json($address->fresh()->load(['city.state']));
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $address = $this->resolveAddress($request, $address);
        $wasDefault = (bool) $address->is_default;

        $address->delete();

        if ($wasDefault) {
            $nextDefault = $request->user()->addresses()->latest('id')->first();
            if ($nextDefault) {
                $nextDefault->update(['is_default' => true]);
            }
        }

        return response()->json([
            'message' => 'Address deleted successfully.',
        ]);
    }

    protected function resolveAddress(Request $request, Address $address): Address
    {
        abort_if($address->user_id !== $request->user()->id, 404, 'Address not found.');

        return $address;
    }

    protected function setDefaultAddress(Request $request, Address $address): void
    {
        $request->user()
            ->addresses()
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);

        if (! $address->is_default) {
            $address->update(['is_default' => true]);
        }
    }
}
