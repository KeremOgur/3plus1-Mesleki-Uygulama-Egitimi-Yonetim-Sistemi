<?php
namespace App\Domain;

class Iban
{
    public static function validTurkish(mixed $value): bool
    {
        if(!is_string($value) || !preg_match('/^TR[0-9]{24}$/D',$value))return false;
        // ISO 13616 / MOD 97-10: move the country/check digits to the end.
        // T=29, R=27. Reduce one digit at a time to avoid integer overflow.
        $digits=substr($value,4).'2927'.substr($value,2,2);$remainder=0;
        foreach(str_split($digits) as $digit)$remainder=($remainder*10+(int)$digit)%97;
        return $remainder===1;
    }
}
