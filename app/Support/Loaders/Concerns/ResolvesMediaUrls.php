<?php

namespace App\Support\Loaders\Concerns;

use App\Models\Gallery;
use Illuminate\Support\Facades\Storage;
use Throwable;

trait ResolvesMediaUrls
{
    protected static function mediaUrl(?string $path, ?string $disk = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        foreach (['http://', 'https://', '//', 'data:', '/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }

        $diskName = $disk ?: config('filesystems.default');

        try {
            return Storage::disk($diskName)->url($path);
        } catch (Throwable) {
            return $path;
        }
    }

    protected static function galleryUrl(?Gallery $gallery): ?string
    {
        if (! $gallery) {
            return null;
        }

        return self::mediaUrl($gallery->path, $gallery->disk);
    }
}
