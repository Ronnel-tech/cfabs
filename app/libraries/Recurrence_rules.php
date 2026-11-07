<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Recurrence_rules {
    public function rule(array $rule): array
    {
        if (array_diff(array_keys($rule), ['frequency','count','interval']) || !in_array($rule['frequency'] ?? null, ['daily','weekly'], TRUE)) throw new DomainException('Choose daily or weekly recurrence.', 422);
        $count = $rule['count'] ?? null; $step = $rule['interval'] ?? 1;
        if (!is_int($count) || $count < 2 || $count > 30 || !is_int($step) || $step < 1 || $step > 4) throw new DomainException('Use 2–30 occurrences and an interval of 1–4 days or weeks.', 422);
        return ['frequency' => $rule['frequency'], 'count' => $count, 'interval' => $step];
    }
    public function dates(string $start_utc, string $end_utc, array $input): array
    {
        $rule = $this->rule($input);
        $utc = new DateTimeZone('UTC'); $campus = new DateTimeZone('Asia/Manila');
        $start = (new DateTimeImmutable($start_utc, $utc))->setTimezone($campus);
        $end = (new DateTimeImmutable($end_utc, $utc))->setTimezone($campus);
        $days = $rule['interval'] * ($rule['frequency'] === 'weekly' ? 7 : 1);
        $dates = [];
        for ($i = 0; $i < $rule['count']; $i++) {
            $offset = '+' . ($i * $days) . ' days';
            $dates[] = [$start->modify($offset)->format(DATE_ATOM), $end->modify($offset)->format(DATE_ATOM)];
        }
        return $dates;
    }
}
