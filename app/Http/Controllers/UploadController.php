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
        $allowedDisks = (array) config('uploads.allowed_disks', ['public']);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', (array) config('uploads.image_mimes', ['jpg', 'jpeg', 'png', 'webp', 'gif'])),
                'max:'.max((int) config('uploads.max_kilobytes', 5120), 1),
            ],
            'disk' => ['sometimes', 'string', Rule::in($allowedDisks)],
        ]);

        $disk = $validated['disk'] ?? (string) config('uploads.default_disk', 'public');

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
