<?php

namespace ApiMedia\Tgs\Support;

use MediaEmbed\MediaEmbed;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

use function app;
use function cache;
use function fclose;
use function getimagesizefromstring;
use function hash;
use function is_array;
use function is_resource;
use function now;
use function preg_match;
use function stream_get_contents;

final class TgsMedia
{
    public static function imageDataCacheKey(Media $media, string $conversion = ''): string
    {
        return 'tgs.media.image_data.'.hash('sha256', $media::class.'|'.$media->getConnection()->getName().'|'.$media->getConnection()->getDatabaseName().'|'.$media->getTable().'|'.$media->getKey().'|'.$media->getCustomProperty('uuid').'|'.$conversion);
    }

    /**
     * Меряет размеры и content-hash конверсии (свежим чтением — без кэша, чтобы регенерация
     * обновляла hash) и складывает в custom_properties['conversions'][$conversion] — оттуда
     * их читает MediaConversionData без обращения к файлу на рендере. Не-картинки / недоступный
     * файл молча пропускаются.
     */
    public static function captureConversionData(Media $media, string $conversion = ''): void
    {
        $measurement = self::measure($media, $conversion);

        if ($measurement === null) {
            return;
        }

        $media->setCustomProperty("conversions.{$conversion}", $measurement);
        $media->save();
    }

    public static function imageData(Media $media, string $conversion = ''): ?array
    {
        return cache()->remember(
            self::imageDataCacheKey($media, $conversion),
            now()->addDay(),
            fn () => self::measure($media, $conversion),
        );
    }

    /**
     * @return array{width: int, height: int, hash: string}|null
     */
    protected static function measure(Media $media, string $conversion): ?array
    {
        $bytes = self::readBytes($media, $conversion);

        if ($bytes === null) {
            return null;
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            return null;
        }

        return [
            'width' => $size[0],
            'height' => $size[1],
            'hash' => hash('xxh3', $bytes),
        ];
    }

    /**
     * Читает сырые байты конверсии/оригинала через Media::stream() — тот же disk-агностичный
     * примитив, которым сама spatie пользуется для cross-disk операций — вместо getPath()
     * + прямого чтения файла, которое предполагает локально читаемый диск и молча теряет
     * картинки на удалённых (напр. S3, backlog #4). Любая ошибка диска/файла → null.
     */
    protected static function readBytes(Media $media, string $conversion): ?string
    {
        try {
            $stream = $media->stream($conversion);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        return $contents === false ? null : $contents;
    }

    public static function getImageData($media, $conversionName = '')
    {
        // Сначала — закэшированные при генерации размеры/hash из custom_properties['conversions']
        // (см. CaptureMediaConversionData); фолбэк на разовый (кэшируемый) замер файла для
        // легаси-медиа. Производные (ratio/aspect/pt) считаем из размеров — файл на рендере не трогаем.
        $stored = $media->getCustomProperty("conversions.{$conversionName}")
            ?? self::imageData($media, $conversionName);

        if (! is_array($stored)) {
            return null;
        }

        $width = isset($stored['width']) ? (int) $stored['width'] : null;
        $height = isset($stored['height']) ? (int) $stored['height'] : null;
        $hasDimensions = $width > 0 && $height > 0;

        return [
            'url' => $media->getUrl($conversionName),
            'hash' => $stored['hash'] ?? null,
            'ratio' => $hasDimensions ? (float) ($width / $height) : null,
            'aspect_ratio' => $hasDimensions ? self::deriveAspectRatio($width, $height) : null,
            'pt' => $hasDimensions ? ($height / $width * 100).'%' : null,
            'height' => $height,
            'width' => $width,
        ];
    }

    protected static function deriveAspectRatio(int $width, int $height): string
    {
        $gcd = self::gcd($width, $height);

        return ($width / $gcd).':'.($height / $gcd);
    }

    public static function getMediaConversionsOutput(Media $media, $includeOriginal = true)
    {
        $conversions = [];

        if ($includeOriginal) {
            $conversions['original'] = self::getImageData($media);
        }

        foreach ($media->getMediaConversionNames() as $conversionName) {
            $conversions[$conversionName] = self::getImageData($media, $conversionName);
        }

        return $conversions;
    }

    public static function parseEmbeddedURL(?string $source): ?string
    {
        if (empty($source)) {
            return null;
        }

        $src = trim($source);

        // Read only the iframe URL, never its other attributes or markup.
        if (preg_match('~<iframe\b[^>]*\s+src\s*=\s*(["\'])(.*?)\1~is', $src, $matches)) {
            $src = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (! self::isHttpUrl($src)) {
            return null;
        }

        // Try to parse the URL
        $parser = app(MediaEmbed::class);
        $mediaObject = $parser->parseUrl($src);

        $src = $mediaObject?->getEmbedSrc() ?? $src;
        // Provider templates may omit the scheme; public output must be absolute.
        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }

        return self::isHttpUrl($src) ? $src : null;
    }

    private static function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
