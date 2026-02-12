<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function states(Request $request): JsonResponse
    {
        $q = trim((string) $request->string('q', ''));
        $hasStateStatus = Schema::hasColumn('states', 'status');
        $hasCityStatus = Schema::hasColumn('cities', 'status');

        $statesQuery = State::query()
            ->select(['id', 'name', 'slug', 'code'])
            ->withCount([
                'cities as cities_count' => static function ($query) use ($hasCityStatus): void {
                    if ($hasCityStatus) {
                        $query->where('status', 'active');
                    }
                },
            ]);

        if ($hasStateStatus) {
            $statesQuery->where('status', 'active');
        }

        $states = $statesQuery
            ->when($q !== '', static function ($query) use ($q): void {
                $query->where(static function ($query) use ($q): void {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('slug', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json($states);
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state_id' => ['nullable', 'integer', Rule::exists('states', 'id')],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $hasStateStatus = Schema::hasColumn('states', 'status');
        $hasCityStatus = Schema::hasColumn('cities', 'status');

        $citiesQuery = City::query()
            ->select(['id', 'state_id', 'name', 'slug', 'code']);

        if ($hasCityStatus) {
            $citiesQuery->where('status', 'active');
        }

        if ($hasStateStatus) {
            $citiesQuery->whereHas('state', static function ($query): void {
                $query->where('status', 'active');
            });
        }

        $cities = $citiesQuery
            ->when(isset($data['state_id']), static function ($query) use ($data): void {
                $query->where('state_id', (int) $data['state_id']);
            })
            ->when($q !== '', static function ($query) use ($q): void {
                $query->where(static function ($query) use ($q): void {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('slug', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json($cities);
    }
}
