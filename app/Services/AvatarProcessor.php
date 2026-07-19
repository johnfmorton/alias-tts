<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Normalizes an uploaded avatar into a small, safe, square WebP.
 *
 * The decode-then-re-encode is the security boundary as much as the resize: the
 * output is pure pixels, so nothing that rode along in the original file — EXIF-
 * embedded code, an appended polyglot payload, trailing junk after the image
 * data — survives the round trip. We never persist the bytes the user uploaded,
 * only what comes out of here. The megapixel ceiling is checked upstream (in the
 * controller's validation) so a small file can't decode into a huge bitmap here.
 */
class AvatarProcessor
{
    /** Longest edge of the stored square avatar; displayed at <=64px (<=192px on retina). */
    public const MAX_EDGE = 512;

    /** WebP quality (0-100). 82 is visually lossless for a photo at this size. */
    public const QUALITY = 82;

    /**
     * Reject sources whose pixel count exceeds this before decoding — a 4 MB file
     * can still decompress to gigapixels (a decompression bomb). 40 MP covers any
     * real phone/DSLR photo while bounding the GD bitmap we allocate.
     */
    public const MAX_SOURCE_PIXELS = 40_000_000;

    /** Decode, orient, center-crop to a square (never upscaling), and return WebP bytes. */
    public function toWebp(UploadedFile $file): string
    {
        $image = (new ImageManager(new Driver))
            ->decodePath($file->getRealPath())
            ->orient()                                    // bake EXIF rotation into pixels before we strip it
            ->coverDown(self::MAX_EDGE, self::MAX_EDGE);  // square cover-crop, capped at the source size

        return (string) $image->encode(new WebpEncoder(quality: self::QUALITY, strip: true));
    }
}
