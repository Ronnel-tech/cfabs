<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Notification_model extends Model {
    private $connection;
    public function __construct()
    {
        parent::__construct(); $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }
    public function record(int $recipient, string $event, string $entity, int $id, string $title, string $message): void
    {
        $this->connection->raw('INSERT INTO notifications (recipient_id,title,message,notification_type,related_entity_type,related_entity_id,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',[$recipient,$title,$message,$event,$entity,$id]);
    }
    public function account(int $id, string $event): void
    {
        $titles = ['created'=>'Campus account created','activated'=>'Campus account activated','deactivated'=>'Campus account deactivated'];
        $this->record($id,'account.'.$event,'user',$id,$titles[$event],$titles[$event].'. Contact campus administration if you need help.');
    }
    public function maintenance(string $kind, int $id, array $record, string $event): void
    {
        $lava = lava_instance();
        $lava->call->model(['Reminder_model','Booking_model']);
        $source = ['kind'=>$kind,'maintenance'=>TRUE,'id'=>$id];
        $resource=$lava->Booking_model->resource($kind,(int)$record[$kind.'_id']);
        $label=($resource[$kind.'_name']??ucfirst($kind)).' · maintenance #'.$id;
        foreach ($lava->Reminder_model->targets($source,$record,$kind.'_maintenance_'.$event) as $recipient) {
            $title = ucfirst($kind).' maintenance '.$event;
            $this->record($recipient,$kind.'_maintenance.'.$event,$kind.'_maintenance',$id,$title,$label.': '.$event.'. Review the maintenance record for current details.');
        }
    }
}
