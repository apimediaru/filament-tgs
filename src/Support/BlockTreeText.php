<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use Closure;

use function array_filter;
use function implode;
use function is_array;
use function is_string;
use function preg_replace;
use function strip_tags;
use function trim;

final class BlockTreeText
{
    private static function visit(array $data, Closure $callback, int $maxDepth, int $depth = 0): void
    {
        if ($depth > $maxDepth) {
            return;
        }
        if (isset($data['type'], $data['data']) && is_string($data['type']) && is_array($data['data'])) {
            $callback($data['type'], $data['data']);
            foreach ($data['data'] as $value) {
                if (is_array($value)) {
                    foreach ($value as $child) {
                        if (is_array($child)) {
                            self::visit($child, $callback, $maxDepth, $depth + 1);
                        }
                    }
                }
            }
        } else {
            foreach ($data as $value) {
                if (is_array($value)) {
                    self::visit($value, $callback, $maxDepth, $depth);
                }
            }
        }
    }

    public static function extractText(?array $data, string $separator = ' ', int $maxDepth = PHP_INT_MAX): ?string
    {
        if (empty($data)) {
            return null;
        }

        $parts = [];

        self::visit($data, function (string $type, array $blockData) use (&$parts) {
            $class = ContentBuilderFactory::blockClassFor($type);

            if ($class === null) {
                return;
            }

            $text = $class::toText($blockData);

            if ($text !== null && $text !== '') {
                $parts[] = self::htmlToText($text);
            }
        }, maxDepth: $maxDepth);

        $result = implode($separator, array_filter($parts));

        return $result ?: null;
    }

    /**
     * Плоский текст из HTML одного блока — одна непрерывная строка: границы блочных
     * элементов и <br> становятся пробелами, иначе strip_tags склеивает абзацы/заголовки/
     * пункты списков без разделителя («…П1Преимущества Испании:высокий…» — унаследованный
     * баг, виден в старом прод-фиде).
     */
    private static function htmlToText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/i', ' ', $html);
        $html = preg_replace('/<\/(p|li|h[1-6]|div|blockquote|figcaption|tr)\s*>/i', ' ', $html);

        $text = strip_tags($html);
        $text = preg_replace('/\s{2,}/u', ' ', $text);

        return trim($text);
    }
}
