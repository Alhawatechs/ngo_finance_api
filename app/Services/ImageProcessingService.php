<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageProcessingService
{
    /** Lazy: building this in the constructor runs on every OrganizationController request (e.g. saving short name) and fails if GD is not installed. */
    protected ?ImageManager $manager = null;

    protected function imageManager(): ImageManager
    {
        if ($this->manager === null) {
            if (! extension_loaded('gd')) {
                throw new \RuntimeException(
                    'GD PHP extension is required for logo image processing. Install php-gd or upload will use a simple file store.'
                );
            }
            $this->manager = new ImageManager(new Driver());
        }

        return $this->manager;
    }

    /**
     * Store logo without Intervention/GD (resize/transparency). Used when GD is missing.
     *
     * @return array{path: string, filename: string, had_transparency: bool|null, width: int, height: int}
     */
    protected function storeLogoWithoutProcessing(UploadedFile $file, string $storagePath): array
    {
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $filename = uniqid('logo_', true) . '.' . $ext;
        Storage::disk('public')->putFileAs($storagePath, $file, $filename);
        $fullPath = $storagePath.'/'.$filename;

        $width = 0;
        $height = 0;
        $path = Storage::disk('public')->path($fullPath);
        if (function_exists('getimagesize')) {
            $info = @getimagesize($path);
            if (is_array($info)) {
                $width = (int) ($info[0] ?? 0);
                $height = (int) ($info[1] ?? 0);
            }
        }

        return [
            'path' => $fullPath,
            'filename' => $filename,
            'had_transparency' => null,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Process uploaded logo - check transparency and make transparent if needed
     */
    public function processLogo(UploadedFile $file, string $storagePath = 'logos'): array
    {
        if (! extension_loaded('gd')) {
            return $this->storeLogoWithoutProcessing($file, $storagePath);
        }

        $image = $this->imageManager()->read($file->getPathname());
        
        // Get original dimensions
        $originalWidth = $image->width();
        $originalHeight = $image->height();
        
        // Check if image has transparency
        $hasTransparency = $this->checkTransparency($file->getPathname(), $file->getMimeType());
        
        // If no transparency, attempt to make background transparent
        if (!$hasTransparency) {
            $image = $this->makeBackgroundTransparent($file->getPathname());
        }
        
        // Resize if too large (max 500x500, maintain aspect ratio)
        $maxSize = 500;
        if ($originalWidth > $maxSize || $originalHeight > $maxSize) {
            $image->scaleDown($maxSize, $maxSize);
        }
        
        // Generate unique filename
        $filename = uniqid('logo_') . '.png';
        $fullPath = $storagePath . '/' . $filename;
        
        // Encode as PNG to preserve transparency
        $encoded = $image->toPng();
        
        // Store the processed image
        Storage::disk('public')->put($fullPath, $encoded);
        
        return [
            'path' => $fullPath,
            'filename' => $filename,
            'had_transparency' => $hasTransparency,
            'width' => $image->width(),
            'height' => $image->height(),
        ];
    }

    /**
     * Check if an image has transparency
     */
    protected function checkTransparency(string $filePath, string $mimeType): bool
    {
        // PNG files can have transparency
        if ($mimeType === 'image/png') {
            return $this->checkPngTransparency($filePath);
        }
        
        // GIF files can have transparency
        if ($mimeType === 'image/gif') {
            return $this->checkGifTransparency($filePath);
        }
        
        // WebP files can have transparency
        if ($mimeType === 'image/webp') {
            return $this->checkWebpTransparency($filePath);
        }
        
        // JPEG/JPG don't support transparency
        if (in_array($mimeType, ['image/jpeg', 'image/jpg'])) {
            return false;
        }
        
        // SVG typically supports transparency
        if ($mimeType === 'image/svg+xml') {
            return true;
        }
        
        return false;
    }

    /**
     * Check PNG transparency by reading header and scanning pixels
     */
    protected function checkPngTransparency(string $filePath): bool
    {
        $image = @imagecreatefrompng($filePath);
        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Check if image has alpha channel by examining pixels
        // Sample corners and center for efficiency
        $samplePoints = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
            [(int)($width / 2), (int)($height / 2)],
        ];

        // Also sample edges
        for ($x = 0; $x < $width; $x += max(1, (int)($width / 20))) {
            $samplePoints[] = [$x, 0];
            $samplePoints[] = [$x, $height - 1];
        }
        for ($y = 0; $y < $height; $y += max(1, (int)($height / 20))) {
            $samplePoints[] = [0, $y];
            $samplePoints[] = [$width - 1, $y];
        }

        foreach ($samplePoints as $point) {
            $rgba = imagecolorat($image, $point[0], $point[1]);
            $alpha = ($rgba >> 24) & 0x7F;
            // Alpha 127 = fully transparent, 0 = fully opaque
            if ($alpha > 0) {
                imagedestroy($image);
                return true;
            }
        }

        imagedestroy($image);
        return false;
    }

    /**
     * Check GIF transparency
     */
    protected function checkGifTransparency(string $filePath): bool
    {
        $image = @imagecreatefromgif($filePath);
        if (!$image) {
            return false;
        }

        $transparentIndex = imagecolortransparent($image);
        imagedestroy($image);
        
        return $transparentIndex >= 0;
    }

    /**
     * Check WebP transparency
     */
    protected function checkWebpTransparency(string $filePath): bool
    {
        $image = @imagecreatefromwebp($filePath);
        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Sample pixels for transparency
        for ($x = 0; $x < $width; $x += max(1, (int)($width / 10))) {
            for ($y = 0; $y < $height; $y += max(1, (int)($height / 10))) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha > 0) {
                    imagedestroy($image);
                    return true;
                }
            }
        }

        imagedestroy($image);
        return false;
    }

    /**
     * Attempt to make background transparent
     * Works best with solid color backgrounds (white, light colors)
     */
    protected function makeBackgroundTransparent(string $filePath)
    {
        $image = $this->imageManager()->read($filePath);
        
        // Get the GD resource for pixel manipulation
        $gdImage = imagecreatefromstring(file_get_contents($filePath));
        if (!$gdImage) {
            return $image;
        }

        $width = imagesx($gdImage);
        $height = imagesy($gdImage);

        // Create a new true color image with alpha channel
        $newImage = imagecreatetruecolor($width, $height);
        
        // Enable alpha blending and save alpha
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        
        // Create transparent color
        $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
        imagefill($newImage, 0, 0, $transparent);
        
        // Detect background color from corners
        $bgColor = $this->detectBackgroundColor($gdImage, $width, $height);
        
        // Tolerance for color matching (0-255)
        $tolerance = 30;

        // Process each pixel
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($gdImage, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Check if pixel color is similar to background
                if ($this->isColorSimilar($r, $g, $b, $bgColor, $tolerance)) {
                    // Calculate transparency based on color distance
                    $distance = $this->colorDistance($r, $g, $b, $bgColor);
                    $alpha = min(127, (int)(127 * (1 - $distance / ($tolerance * 3))));
                    
                    if ($alpha > 100) {
                        // Nearly background color - make fully transparent
                        $newColor = imagecolorallocatealpha($newImage, $r, $g, $b, 127);
                    } else {
                        // Partial transparency for anti-aliasing
                        $newColor = imagecolorallocatealpha($newImage, $r, $g, $b, $alpha);
                    }
                } else {
                    // Keep original color, fully opaque
                    $newColor = imagecolorallocatealpha($newImage, $r, $g, $b, 0);
                }
                
                imagesetpixel($newImage, $x, $y, $newColor);
            }
        }

        // Save to temp file and read back with Intervention
        $tempPath = sys_get_temp_dir() . '/' . uniqid('logo_') . '.png';
        imagepng($newImage, $tempPath);
        
        imagedestroy($gdImage);
        imagedestroy($newImage);
        
        $processedImage = $this->imageManager()->read($tempPath);
        @unlink($tempPath);
        
        return $processedImage;
    }

    /**
     * Detect background color by sampling corners and edges
     */
    protected function detectBackgroundColor($gdImage, int $width, int $height): array
    {
        $cornerColors = [];
        
        // Sample corners
        $corners = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
        ];
        
        foreach ($corners as $corner) {
            $rgb = imagecolorat($gdImage, $corner[0], $corner[1]);
            $cornerColors[] = [
                'r' => ($rgb >> 16) & 0xFF,
                'g' => ($rgb >> 8) & 0xFF,
                'b' => $rgb & 0xFF,
            ];
        }
        
        // Find most common corner color (or average)
        // For simplicity, use the top-left corner if it's light enough
        $topLeft = $cornerColors[0];
        $brightness = ($topLeft['r'] + $topLeft['g'] + $topLeft['b']) / 3;
        
        // If bright enough, likely a light background
        if ($brightness > 200) {
            return $topLeft;
        }
        
        // Calculate average of corners
        $avgR = array_sum(array_column($cornerColors, 'r')) / 4;
        $avgG = array_sum(array_column($cornerColors, 'g')) / 4;
        $avgB = array_sum(array_column($cornerColors, 'b')) / 4;
        
        return ['r' => (int)$avgR, 'g' => (int)$avgG, 'b' => (int)$avgB];
    }

    /**
     * Check if two colors are similar within tolerance
     */
    protected function isColorSimilar(int $r1, int $g1, int $b1, array $color2, int $tolerance): bool
    {
        return abs($r1 - $color2['r']) <= $tolerance &&
               abs($g1 - $color2['g']) <= $tolerance &&
               abs($b1 - $color2['b']) <= $tolerance;
    }

    /**
     * Calculate color distance
     */
    protected function colorDistance(int $r1, int $g1, int $b1, array $color2): float
    {
        return abs($r1 - $color2['r']) + abs($g1 - $color2['g']) + abs($b1 - $color2['b']);
    }
}
