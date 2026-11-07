<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
require dirname(__DIR__) . '/app/libraries/Booking_availability.php';

$availability = new Booking_availability();
$cases = [
    'no reservations' => [10, 9, 12, [], 10],
    'approved quantity' => [10, 9, 11, [['start' => 9, 'end' => 11, 'quantity' => 6]], 4],
    'back-to-back reservations use peak, not sum' => [10, 9, 12, [
        ['start' => 9, 'end' => 10, 'quantity' => 6],
        ['start' => 10, 'end' => 12, 'quantity' => 5],
    ], 4],
    'simultaneous reservations add' => [10, 9, 12, [
        ['start' => 9, 'end' => 11, 'quantity' => 6],
        ['start' => 10, 'end' => 12, 'quantity' => 3],
    ], 1],
    'end boundary is available' => [1, 11, 12, [['start' => 9, 'end' => 11, 'quantity' => 1]], 1],
    'start boundary is available' => [1, 9, 11, [['start' => 11, 'end' => 12, 'quantity' => 1]], 1],
    'individual item unavailable' => [1, 9, 12, [['start' => 10, 'end' => 11, 'quantity' => 1]], 0],
    'reservation covers requested interval' => [10, 10, 11, [['start' => 9, 'end' => 12, 'quantity' => 6]], 4],
    'overallocated inventory never negative' => [2, 9, 11, [['start' => 9, 'end' => 11, 'quantity' => 3]], 0],
];
foreach ($cases as $name => [$stock, $start, $end, $blocks, $expected]) {
    if ($availability->available_quantity($stock, $start, $end, $blocks) !== $expected) {
        throw new RuntimeException('Failed: ' . $name);
    }
}
foreach ([[-1, 9, 11, []], [1, 11, 11, []], [1, 9, 11, [
    ['start' => 9, 'end' => 11, 'quantity' => -1],
]]] as $arguments) {
    try {
        $availability->available_quantity(...$arguments);
        throw new RuntimeException('Invalid input was accepted.');
    } catch (InvalidArgumentException $expected) {
        // Expected validation failure.
    }
}
echo "Passed 12 availability checks.\n";
