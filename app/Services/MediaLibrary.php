<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Image uploads for the editor.
 *
 * Built on PHP's own GD extension: no Composer package, nothing to install,
 * nothing to break on the next deploy. Every upload becomes WebP — a cover
 * image is the heaviest thing on a blog page, and WebP is roughly a third
 * the size of the JPEG it replaces at the same quality.
 */
class MediaLibrary
{
    /** Widths generated alongside the original, for `srcset`. */
    public const WIDTHS = [480, 960, 1600];

    public const MAX_WIDTH = 2400;

    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function store(UploadedFile $file, ?string $alt = null): Media
    {
        if (! in_array($file->getMimeType(), self::ACCEPTED, true)) {
            throw new RuntimeException('Only JPEG, PNG, GIF and WebP images are accepted.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw new RuntimeException('That file is not a readable image.');
        }

        $image = $this->fixOrientation($image, $file->getRealPath(), $file->getMimeType());

        // Palette images (many PNGs and every GIF) resize badly until they
        // are true colour, and lose their transparency without these two.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $image = $this->capWidth($image, self::MAX_WIDTH);

        $uuid = (string) Str::uuid();
        $disk = config('dbelo.storage.media', 'public');
        $folder = 'media/'.now()->format('Y/m');

        $path = "{$folder}/{$uuid}.webp";
        Storage::disk($disk)->put($path, $this->toWebp($image));

        $variants = [];

        foreach (self::WIDTHS as $width) {
            if (imagesx($image) <= $width) {
                continue;   // never upscale: it only adds bytes
            }

            $small = $this->capWidth($image, $width, clone: true);
            $variantPath = "{$folder}/{$uuid}-{$width}.webp";

            Storage::disk($disk)->put($variantPath, $this->toWebp($small));
            imagedestroy($small);

            $variants[$width] = $variantPath;
        }

        $media = Media::create([
            'user_id' => auth()->id(),
            'disk' => $disk,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'alt' => $alt,
            'mime' => 'image/webp',
            'size' => Storage::disk($disk)->size($path),
            'width' => imagesx($image),
            'height' => imagesy($image),
            'variants' => $variants ?: null,
        ]);

        imagedestroy($image);

        return $media;
    }

    /**
     * Phones write the rotation in EXIF instead of rotating the pixels, so a
     * photo that looks upright on the desktop arrives sideways on the page.
     */
    protected function fixOrientation(\GdImage $image, string $path, ?string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($path)['Orientation'] ?? null;

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => null,
        };

        if ($angle === null) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        imagedestroy($image);

        return $rotated ?: $image;
    }

    protected function capWidth(\GdImage $image, int $max, bool $clone = false): \GdImage
    {
        if (imagesx($image) <= $max) {
            return $clone ? $this->copy($image) : $image;
        }

        $resized = imagescale($image, $max, -1, IMG_BICUBIC);

        if ($resized === false) {
            return $clone ? $this->copy($image) : $image;
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        if (! $clone) {
            imagedestroy($image);
        }

        return $resized;
    }

    protected function copy(\GdImage $image): \GdImage
    {
        $copy = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $copy;
    }

    protected function toWebp(\GdImage $image, int $quality = 82): string
    {
        ob_start();
        imagewebp($image, null, $quality);

        return (string) ob_get_clean();
    }
}
