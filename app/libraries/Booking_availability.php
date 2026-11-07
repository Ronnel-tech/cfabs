<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Interval calculations only; callers must fetch and lock current inventory. */
class Booking_availability {
    /**
     * Return minimum free stock throughout [start, end), using UTC instants.
     * Blocks contain start, end (Unix timestamps), and positive quantity.
     * Pass only reserving bookings and temporary unavailability; exclude pending
     * requests and stock already excluded from usable quantity.
     */
    public function available_quantity(int $usable, int $start, int $end, array $blocks): int
    {
        if ($usable < 0 || $start >= $end) {
            throw new InvalidArgumentException('Invalid stock or booking interval.');
        }
        return max(0, $usable - $this->reserved_quantity($start, $end, $blocks));
    }

    public function reserved_quantity(int $start, int $end, array $blocks): int
    {
        if ($start >= $end) throw new InvalidArgumentException('Invalid booking interval.');
        $events = [];
        foreach ($blocks as $block) {
            if (!isset($block['start'], $block['end'], $block['quantity'])
                || !is_int($block['start']) || !is_int($block['end'])
                || !is_int($block['quantity']) || $block['quantity'] <= 0
                || $block['start'] >= $block['end']) {
                throw new InvalidArgumentException('Invalid availability block.');
            }
            $left = max($start, $block['start']);
            $right = min($end, $block['end']);
            if ($left >= $right) {
                continue;
            }
            $events[$left] = ($events[$left] ?? 0) + $block['quantity'];
            $events[$right] = ($events[$right] ?? 0) - $block['quantity'];
        }
        ksort($events, SORT_NUMERIC);
        $reserved = 0;
        $peak = 0;
        foreach ($events as $change) {
            $reserved += $change;
            $peak = max($peak, $reserved);
        }
        return $peak;
    }
}
