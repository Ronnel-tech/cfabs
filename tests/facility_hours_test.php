<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
require dirname(__DIR__) . '/app/libraries/Facility_hours.php';
$hours = new Facility_hours();
foreach ([['2030-06-01 00:00:00','2030-06-01 09:00:00'], ['2030-06-02 01:00:00','2030-06-02 03:00:00']] as [$start,$end]) $hours->validate($start,$end);
foreach ([['2030-06-01 23:59:59','2030-06-02 09:00:00'], ['2030-06-01 00:00:00','2030-06-01 09:00:01'], ['2030-06-01 00:00:00','2030-06-02 01:00:00'], ['2030-06-01 02:00:00','2030-06-01 01:00:00']] as [$start,$end]) {
    try { $hours->validate($start,$end); throw new RuntimeException('Invalid facility hours accepted.'); }
    catch (DomainException $expected) {}
}
echo "Passed 6 campus-hours checks, including weekends and UTC boundaries.\n";
