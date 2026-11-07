<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Booking_rules {
    public function advance_window(string $start_utc, ?DateTimeImmutable $now = null): array
    {
        $campus = new DateTimeZone('Asia/Manila');
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($campus);
        $latest = $now->modify('+30 days')->format('Y-m-d');
        $start = (new DateTimeImmutable($start_utc, new DateTimeZone('UTC')))->setTimezone($campus)->format('Y-m-d');
        return ['days' => 30, 'timezone' => 'Asia/Manila', 'latest_start_date' => $latest, 'within_limit' => $start <= $latest];
    }

    public function require_advance_window(string $start_utc): void
    {
        if (!$this->advance_window($start_utc)['within_limit']) throw new DomainException('Bookings may start at most 30 calendar days ahead (Asia/Manila).', 422);
    }

    public function interval($start, $end, bool $future = FALSE): array
    {
        $left = $this->instant($start);
        $right = $this->instant($end);
        if ($left >= $right || ($future && $left->getTimestamp() <= time())) {
            throw new DomainException('Choose a future start and an end after the start.', 422);
        }
        return [$left->format('Y-m-d H:i:s'), $right->format('Y-m-d H:i:s')];
    }

    public function positive_id($value): int
    {
        if ((!is_string($value) && !is_int($value))
            || ($id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === FALSE) {
            throw new DomainException('A positive integer is required.', 422);
        }
        return $id;
    }

    public function text($value, int $maximum, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $maximum) {
            throw new DomainException("{$field} is required and must be at most {$maximum} bytes.", 422);
        }
        return trim($value);
    }

    private function instant($value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            throw new DomainException('Dates must use ISO 8601 with seconds and a timezone offset.', 422);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $value));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== FALSE && ($errors['warning_count'] || $errors['error_count']))) {
            throw new DomainException('Invalid date or time.', 422);
        }
        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }
}
