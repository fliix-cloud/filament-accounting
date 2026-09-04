<?php

namespace FilamentAccounting\Support;

final class RichText
{
    public static function sanitize(?string $html): ?string
    {
        if (! filled($html)) {
            return null;
        }

        $clean = strip_tags((string) $html, '<p><br><strong><b><em><i><ul><ol><li>');
        $clean = preg_replace('/<(p|br|strong|b|em|i|ul|ol|li)\b[^>]*>/i', '<$1>', $clean) ?? '';
        $clean = trim($clean);

        return $clean !== '' ? $clean : null;
    }

    public static function plainText(?string $html): string
    {
        $html = self::sanitize($html) ?? '';
        $html = preg_replace('/<li>/i', "\n- ", $html) ?? $html;
        $html = preg_replace('/<\/(p|li|ul|ol)>|<br>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function catalogLine(string $name, ?string $description): string
    {
        $description = self::sanitize($description);

        return '<p><strong>'.e($name).'</strong></p>'.($description ?? '');
    }
}
