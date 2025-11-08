<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Support\Loaders\BrandLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $status = $request->string('status', 'active');

        $brands = Brand::query()
            ->with([
                'products:id,brand_id',
            ])
            ->when($status, static function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => BrandLoader::make($brand));

        return response()->json($brands);
    }
}
