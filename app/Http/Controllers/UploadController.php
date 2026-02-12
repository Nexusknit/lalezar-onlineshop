<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:upload.store')->only('store');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120'],
            'disk' => ['sometimes', 'string', Rule::in(array_keys(config('filesystems.disks')))],
        ]);

        $disk = $validated['disk'] ?? 'public';

        $path = $request->file('file')->store(
            'uploads/'.date('Y/m/d'),
            $disk
        );

        return response()->json([
            'message' => 'File uploaded successfully.',
            'disk' => $disk,
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
        ], 201);
    }
}
