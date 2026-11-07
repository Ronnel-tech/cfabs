<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
require dirname(__DIR__) . '/app/libraries/Booking_rules.php';
$rules = new Booking_rules();
$interval = $rules->interval('2030-11-10T09:00:00+08:00', '2030-11-10T11:00:00+08:00');
if ($interval !== ['2030-11-10 01:00:00', '2030-11-10 03:00:00']) {
    throw new RuntimeException('Campus dates must convert to UTC.');
}
$invalid = [
    fn() => $rules->interval('2030-11-10T09:00:00', '2030-11-10T11:00:00'),
    fn() => $rules->interval('2030-02-31T09:00:00Z', '2030-03-01T11:00:00Z'),
    fn() => $rules->interval('2030-11-10T11:00:00Z', '2030-11-10T09:00:00Z'),
    fn() => $rules->interval('2000-11-10T09:00:00Z', '2000-11-10T11:00:00Z', TRUE),
    fn() => $rules->interval([], []),
    fn() => $rules->positive_id(TRUE),
    fn() => $rules->positive_id(-1),
    fn() => $rules->positive_id('1 OR 1=1'),
    fn() => $rules->positive_id([]),
    fn() => $rules->text(' ', 10, 'reason'),
    fn() => $rules->text(str_repeat('a', 11), 10, 'reason'),
];
foreach ($invalid as $operation) {
    try {
        $operation();
        throw new RuntimeException('Invalid booking input was accepted.');
    } catch (DomainException $expected) {
        if ($expected->getCode() !== 422) throw $expected;
    }
}
echo "Passed 12 booking validation checks.\n";
