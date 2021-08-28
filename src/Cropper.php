<?php

namespace LandKit\Cropper;

use Exception;
use WebPConvert\Convert\Exceptions\ConversionFailedException;
use WebPConvert\WebPConvert;

use function LandKit\Functions\strSlug;

class Cropper
{
    /**
     * @var string
     */
    private $cachePath;

    /**
     * @var string
     */
    private $imagePath;

    /**
     * @var string
     */
    private $imageName;

    /**
     * @var string
     */
    private $imageMime;

    /**
     * @var int
     */
    private $quality;

    /**
     * @var int
     */
    private $compressor;

    /**
     * @var bool
     */
    private $webP;

    /**
     * @var string[]
     */
    private static $allowedExt = [
        'image/jpeg',
        'image/png'
    ];

    /**
     * Create new Cropper instance.
     *
     * @param string $cachePath
     * @param int $quality
     * @param int $compressor
     * @param bool $webP
     * @throws Exception
     */
    public function __construct(string $cachePath, int $quality = 75, int $compressor = 5, bool $webP = false)
    {
        $this->cachePath = $cachePath;
        $this->quality = $quality;
        $this->compressor = $compressor;
        $this->webP = $webP;

        if (!file_exists($this->cachePath) || !is_dir($this->cachePath)) {
            if (!mkdir($this->cachePath, 0755, true)) {
                throw new Exception('Could not create cache folder');
            }
        }
    }

    /**
     * @param string $imagePath
     * @param int $width
     * @param int|null $height
     * @return string
     */
    public function make(string $imagePath, int $width, int $height = null): string
    {
        if (!file_exists($imagePath)) {
            return 'Image not found';
        }

        $this->imagePath = $imagePath;
        $this->imageName = $this->name($this->imagePath, $width, $height);
        $this->imageMime = mime_content_type($this->imagePath);

        if (!in_array($this->imageMime, self::$allowedExt)) {
            return 'Not a valid JPG or PNG image';
        }

        return $this->image($width, $height);
    }

    /**
     * @param string|null $imagePath
     * @return void
     */
    public function flush(string $imagePath = null)
    {
        foreach (scandir($this->cachePath) as $file) {
            $file = "{$this->cachePath}/{$file}";

            if ($imagePath && strpos($file, $this->hash($imagePath))) {
                $this->imageDestroy($file);
            } elseif (!$imagePath) {
                $this->imageDestroy($file);
            }
        }
    }

    /**
     * @param string $image
     * @param bool $unlinkImage
     * @return string
     */
    public function toWebP(string $image, bool $unlinkImage = true): string
    {
        try {
            $webPConverted = pathinfo($image)['dirname'] . '/' . pathinfo($image)['filename'] . '.webp';
            WebPConvert::convert($image, $webPConverted, ['default-quality' => $this->quality]);

            if ($unlinkImage) {
                unlink($image);
            }

            return $webPConverted;
        } catch (ConversionFailedException $exception) {
            return $image;
        }
    }

    /**
     * @param string $name
     * @param int|null $width
     * @param int|null $height
     * @return string
     */
    protected function name(string $name, int $width = null, int $height = null): string
    {
        $name = strSlug(pathinfo($name)['filename']);
        $hash = $this->hash($this->imagePath);

        $widthName = !$width ? '' : "-{$width}";
        $heightName = !$height ? '' : "x{$height}";

        return "{$name}{$widthName}{$heightName}-{$hash}";
    }

    /**
     * @param string $path
     * @return string
     */
    protected function hash(string $path): string
    {
        return hash('crc32', pathinfo($path)['basename']);
    }

    /**
     * @param int $width
     * @param int|null $height
     * @return string
     */
    private function image(int $width, int $height = null): string
    {
        $imageWebP = "{$this->cachePath}/{$this->imageName}.webp";
        $imageExt = "{$this->cachePath}/{$this->imageName}." . pathinfo($this->imagePath)['extension'];

        if ($this->webP && file_exists($imageWebP) && is_file($imageWebP)) {
            return $imageWebP;
        }

        if (file_exists($imageExt) && is_file($imageExt)) {
            return $imageExt;
        }

        return $this->imageCache($width, $height);
    }

    /**
     * @param int $width
     * @param int|null $height
     * @return string
     */
    private function imageCache(int $width, int $height = null): string
    {
        list($srcW, $srcH) = getimagesize($this->imagePath);
        $height = ($height ?? ($width * $srcH) / $srcW);

        $srcX = 0;
        $srcY = 0;

        $cmpX = $srcW / $width;
        $cmpY = $srcH / $height;

        if ($cmpX > $cmpY) {
            $srcW = round($srcW / $cmpX * $cmpY);
            $srcX = round($srcW - ($srcW / $cmpX * $cmpY)); // 2
        } elseif ($cmpY > $cmpX) {
            $srcH = round($srcH / $cmpY * $cmpX);
            $srcY = round($srcH - ($srcH / $cmpY * $cmpX)); // 2
        }

        $srcW = (int) $srcW;
        $srcH = (int) $srcH;
        $srcX = (int) $srcX;
        $srcY = (int) $srcY;

        if ($this->imageMime == 'image/jpeg') {
            return $this->fromJpg($width, $height, $srcX, $srcY, $srcW, $srcH);
        }

        if ($this->imageMime == 'image/png') {
            return $this->fromPng($width, $height, $srcX, $srcY, $srcW, $srcH);
        }

        return '';
    }

    /**
     * @param string $imagePatch
     * @return void
     */
    private function imageDestroy(string $imagePatch)
    {
        if (file_exists($imagePatch) && is_file($imagePatch)) {
            unlink($imagePatch);
        }
    }

    /**
     * @param int $width
     * @param int $height
     * @param int $srcX
     * @param int $srcY
     * @param int $srcW
     * @param int $srcH
     * @return string
     */
    private function fromJpg(int $width, int $height, int $srcX, int $srcY, int $srcW, int $srcH): string
    {
        $thumb = imagecreatetruecolor($width, $height);
        $source = imagecreatefromjpeg($this->imagePath);

        imagecopyresampled($thumb, $source, 0, 0, $srcX, $srcY, $width, $height, $srcW, $srcH);
        imagejpeg($thumb, "{$this->cachePath}/{$this->imageName}.jpg", $this->quality);

        imagedestroy($thumb);
        imagedestroy($source);

        if ($this->webP) {
            return $this->toWebP("{$this->cachePath}/{$this->imageName}.jpg");
        }

        return "{$this->cachePath}/{$this->imageName}.jpg";
    }

    /**
     * @param int $width
     * @param int $height
     * @param int $srcX
     * @param int $srcY
     * @param int $srcW
     * @param int $srcH
     * @return string
     */
    private function fromPng(int $width, int $height, int $srcX, int $srcY, int $srcW, int $srcH): string
    {
        $thumb = imagecreatetruecolor($width, $height);
        $source = imagecreatefrompng($this->imagePath);

        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $source, 0, 0, $srcX, $srcY, $width, $height, $srcW, $srcH);
        imagepng($thumb, "{$this->cachePath}/{$this->imageName}.png", $this->compressor);

        imagedestroy($thumb);
        imagedestroy($source);

        if ($this->webP) {
            return $this->toWebP("{$this->cachePath}/{$this->imageName}.png");
        }

        return "{$this->cachePath}/{$this->imageName}.png";
    }
}