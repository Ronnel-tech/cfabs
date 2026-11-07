<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
require dirname(__DIR__) . '/app/libraries/Recurrence_rules.php';
$rules = new Recurrence_rules();
$weekly = $rules->dates('2032-02-23 01:00:00', '2032-02-23 02:00:00', ['frequency' => 'weekly', 'count' => 3]);
if ($weekly[1] !== ['2032-03-01T09:00:00+08:00','2032-03-01T10:00:00+08:00']) throw new RuntimeException('Weekly campus clock or leap-year rollover failed.');
$daily = $rules->dates('2030-01-30 23:00:00', '2030-02-01 01:00:00', ['frequency' => 'daily', 'count' => 2, 'interval' => 2]);
if ($daily[1] !== ['2030-02-02T07:00:00+08:00','2030-02-03T09:00:00+08:00']) throw new RuntimeException('Daily rollover must preserve multi-day asset duration.');
foreach ([['frequency'=>'monthly','count'=>2],['frequency'=>'daily','count'=>1],['frequency'=>'weekly','count'=>31],['frequency'=>'daily','count'=>2,'interval'=>0],['frequency'=>'daily','count'=>2,'interval'=>5],['frequency'=>'daily','count'=>TRUE],['frequency'=>'daily','count'=>2,'extra'=>1]] as $invalid) {
    try { $rules->rule($invalid); throw new RuntimeException('Invalid recurrence accepted.'); } catch (DomainException $expected) {}
}
echo "Passed 9 recurrence planning checks.\n";
