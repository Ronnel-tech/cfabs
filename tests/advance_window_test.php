<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
require dirname(__DIR__) . '/app/libraries/Booking_rules.php';
$rules = new Booking_rules();
$now = new DateTimeImmutable('2030-01-01T16:30:00Z'); // Jan 2 in Manila.
$cases = [
    ['2030-01-02 00:00:00', TRUE],
    ['2030-02-01 15:59:59', TRUE], // Last second of campus day 30.
    ['2030-02-01 16:00:00', FALSE],
    ['2030-02-10 01:00:00', FALSE],
];
foreach ($cases as [$start,$expected]) {
    $window = $rules->advance_window($start, $now);
    if ($window['within_limit'] !== $expected || $window['latest_start_date'] !== '2030-02-01') throw new RuntimeException('Campus advance-window boundary failed.');
}
$leap = $rules->advance_window('2032-03-01 01:00:00', new DateTimeImmutable('2032-01-31T00:00:00+08:00'));
if (!$leap['within_limit'] || $leap['latest_start_date'] !== '2032-03-01') throw new RuntimeException('Leap-year boundary failed.');
echo "Passed 5 advance-booking checks.\n";
