<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Confirmed campus policy: every day, 08:00–17:00 Asia/Manila. */
class Facility_hours {
    private const TIMEZONE = 'Asia/Manila';
    private const OPEN = '08:00:00';
    private const CLOSE = '17:00:00';

    public function schedule(): array
    {
        return ['timezone' => self::TIMEZONE, 'opens' => substr(self::OPEN, 0, 5), 'closes' => substr(self::CLOSE, 0, 5), 'weekdays' => [1,2,3,4,5,6,7], 'turnover_buffer_minutes' => 0];
    }
    public function validate(string $start_utc, string $end_utc): void
    {
        $utc = new DateTimeZone('UTC'); $campus = new DateTimeZone(self::TIMEZONE);
        $start = (new DateTimeImmutable($start_utc, $utc))->setTimezone($campus);
        $end = (new DateTimeImmutable($end_utc, $utc))->setTimezone($campus);
        if ($start >= $end || $start->format('Y-m-d') !== $end->format('Y-m-d')
            || $start->format('H:i:s') < self::OPEN || $end->format('H:i:s') > self::CLOSE) {
            throw new DomainException('Facility bookings must stay within 8 AM–5 PM on one day (Asia/Manila).', 422);
        }
    }
}
