<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = $perPage > 0 ? min($perPage, 100) : 15;

        $likes = $request->user()
            ->likes()
            ->with('model')
            ->latest()
            ->paginate($perPage)
            ->through(static function ($like) {
                return [
                    'id' => $like->id,
                    'liked_at' => $like->created_at,
                    'type' => class_basename($like->model_type),
                    'model' => $like->model,
                ];
            });

        return response()->json($likes);
    }
}
