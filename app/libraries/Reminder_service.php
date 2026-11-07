<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Reminder_service {
    private $lava;
    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->model(['Reminder_model','Booking_model']);
        date_default_timezone_set('UTC');
        $this->lava->db->raw("SET SESSION time_zone = '+00:00'");
    }
    public function dispatch(bool $send, int $hours, ?int $recipient_filter = null): array
    {
        if ($hours < 1 || $hours > 168) throw new DomainException('Reminder horizon must be 1–168 hours.');
        $model = $this->lava->Reminder_model;
        if (!$model->acquire_lock()) throw new DomainException('Another reminder worker is running.');
        $counts = ['dry_run' => !$send, 'eligible' => 0, 'already_sent' => 0, 'inserted' => 0];
        try {
            foreach ($model->candidates($hours) as $source) {
                $initial = $model->record($source);
                if (!$initial) continue;
                $this->lava->db->raw('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $this->lava->db->transaction();
                try {
                    $resource = $this->lava->Booking_model->resource($source['kind'], (int) $initial[$source['kind'] . '_id'], TRUE);
                    $record = $model->record($source, TRUE);
                    $event = $record ? $this->event($source, $record, time(), $hours * 3600) : null;
                    if ($event !== null) {
                        [$name, $due] = $event;
                        $entity = $source['kind'] . ($source['maintenance'] ? '_maintenance' : '_booking');
                        foreach ($model->targets($source, $record, $name) as $recipient) {
                            if ($recipient_filter !== null && $recipient !== $recipient_filter) continue;
                            $key = hash('sha256', json_encode([$entity, $source['id'], $name, $due, $recipient], JSON_THROW_ON_ERROR));
                            if ($model->exists($key)) { $counts['already_sent']++; continue; }
                            $counts['eligible']++;
                            $label = $source['maintenance'] ? $resource[$source['kind'] . '_name'] : $record['booking_reference'];
                            if ($send && $model->notify($recipient, $name, $entity, $source['id'], $due, $key, $label)) $counts['inserted']++;
                        }
                    }
                    $this->lava->db->commit();
                } catch (Throwable $error) { $this->lava->db->roll_back(); throw $error; }
            }
            return $counts;
        } finally { $model->release_lock(); }
    }
    public function event(array $source, array $row, int $now, int $horizon): ?array
    {
        if ($source['maintenance']) {
            if (!in_array($row['status'], ['scheduled','in_progress'], TRUE)) return null;
            $due = $row[$source['kind'] === 'facility' ? 'end_datetime' : 'expected_completion'];
            return $due !== null && strtotime($due . ' UTC') <= $now ? [$source['kind'] . '_maintenance_overdue', $due] : null;
        }
        if (in_array($row['status'], ['APPROVED','READY_FOR_RELEASE'], TRUE)) {
            $due = $row[$source['kind'] === 'facility' ? 'start_datetime' : 'usage_start'];
            $timestamp = strtotime($due . ' UTC');
            return $timestamp > $now && $timestamp <= $now + $horizon ? [$source['kind'] . '_upcoming', $due] : null;
        }
        if ($source['kind'] === 'asset' && $row['status'] === 'RELEASED'
            && (int) ($row['quantity_released'] ?? $row['quantity_requested']) - (int) ($row['quantity_returned'] ?? 0) - (int) $row['quantity_lost'] > 0) {
            $due = $row['expected_return']; $timestamp = strtotime($due . ' UTC');
            if ($timestamp <= $now) return ['asset_overdue', $due];
            if ($timestamp <= $now + $horizon) return ['asset_return_due', $due];
        }
        return null;
    }
}
