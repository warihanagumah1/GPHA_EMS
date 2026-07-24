<?php

namespace App\Support;

class RichText
{
    private const ALLOWED_TAGS='<p><br><strong><b><u><em><i><ul><ol><li>';

    public static function clean(?string $value): ?string
    {
        if ($value === null) return null;

        $value=preg_replace('#<(script|style)[^>]*>.*?</\1>#is','',$value) ?? '';
        $value=strip_tags($value,self::ALLOWED_TAGS);
        $value=preg_replace('/<(p|br|strong|b|u|em|i|ul|ol|li)\b[^>]*>/i','<$1>',$value) ?? '';
        if (preg_match('/<li>/i',$value) && !preg_match('/<(ul|ol)>/i',$value)) {
            $value='<ul>'.$value.'</ul>';
        }
        return trim($value);
    }

    public static function plain(?string $value): string
    {
        $value=preg_replace('#</?(p|div|li|ul|ol|br)\b[^>]*>#i',' ',(string)$value) ?? '';
        return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($value))) ?? '');
    }
}
