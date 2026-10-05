<?php
namespace App\Domain;
use Carbon\CarbonImmutable;
use App\Models\DomainRecord;
class BusinessCalendar
{
    public function deadline(string $date,int $days,DomainRecord $term): CarbonImmutable
    {
        $day=CarbonImmutable::parse($date,'Europe/Istanbul')->startOfDay();
        while ($days>0) { $day=$day->addDay(); if (!$day->isWeekend() && !in_array($day->toDateString(),$term->holiday_dates??[])) $days--; }
        return $day->endOfDay();
    }
}
