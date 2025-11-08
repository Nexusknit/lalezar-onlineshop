<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\TeamMember;
use App\Support\Loaders\TeamLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $team = TeamMember::query()
            ->when($request->filled('status'), static function ($query) use ($request) {
                $query->where('status', $request->string('status'));
            }, static function ($query) {
                $query->where('status', 'active');
            })
            ->orderBy('order_column')
            ->orderBy('name')
            ->get()
            ->map(fn (TeamMember $member) => TeamLoader::make($member));

        return response()->json($team);
    }
}
