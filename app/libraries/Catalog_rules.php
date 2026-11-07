<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Catalog_rules {
    private const FIELDS = [
        'departments' => ['department_code' => 30, 'department_name' => 150, 'description' => -4000],
        'facility_categories' => ['category_name' => 100, 'description' => -4000],
        'asset_categories' => ['category_name' => 100, 'description' => -4000],
        'facilities' => ['facility_code' => 50, 'facility_name' => 150, 'category_id' => 'id', 'capacity' => 'positive',
            'building' => -120, 'location_details' => -255, 'description' => -4000, 'image_path' => -255],
        'assets' => ['asset_code' => 60, 'asset_name' => 150, 'category_id' => 'id', 'owning_department_id' => 'id',
            'tracking_mode' => 'mode', 'total_quantity' => 'quantity', 'usable_quantity' => 'quantity',
            'description' => -4000, 'location' => -180, 'custodian_id' => 'optional_id', 'condition_status' => 'condition'],
        'asset_instances' => ['asset_tag' => 60, 'serial_number' => -120, 'condition_status' => 'condition', 'location' => -180],
    ];
    private const REQUIRED = [
        'departments' => ['department_code', 'department_name'],
        'facility_categories' => ['category_name'], 'asset_categories' => ['category_name'],
        'facilities' => ['facility_code', 'facility_name', 'category_id', 'capacity'],
        'assets' => ['asset_code', 'asset_name', 'category_id', 'owning_department_id', 'tracking_mode'],
        'asset_instances' => ['asset_tag'],
    ];
    private $rules;

    public function __construct()
    {
        $lava = lava_instance();
        $lava->call->library('booking_rules');
        $this->rules = $lava->booking_rules;
    }

    public function fields(string $table, array $input, bool $creating): array
    {
        if (!isset(self::FIELDS[$table])) throw new InvalidArgumentException('Unknown catalog.');
        if (!$input || array_diff(array_keys($input), array_keys(self::FIELDS[$table]))) {
            throw new DomainException('Provide supported catalog fields only; use the status endpoint for status changes.', 422);
        }
        if ($creating && array_diff(self::REQUIRED[$table], array_keys($input))) throw new DomainException('Required catalog fields are missing.', 422);
        $result = [];
        foreach ($input as $field => $value) {
            $type = self::FIELDS[$table][$field];
            if (is_int($type)) {
                $result[$field] = $type < 0 && ($value === null || $value === '') ? null : $this->rules->text($value, abs($type), $field);
            } elseif ($type === 'optional_id') {
                $result[$field] = $value === null ? null : $this->rules->positive_id($value);
            } elseif ($type === 'id') {
                $result[$field] = $this->rules->positive_id($value);
            } elseif ($type === 'quantity' || $type === 'positive') {
                if (!is_int($value) || $value < ($type === 'positive' ? 1 : 0) || $value > 4294967295) {
                    throw new DomainException("{$field} must be a valid whole-number quantity.", 422);
                }
                $result[$field] = $value;
            } else {
                $choices = $type === 'mode' ? ['quantity', 'individual'] : ['good', 'fair', 'damaged', 'lost', 'inspection'];
                if (!in_array($value, $choices, TRUE)) throw new DomainException("Invalid {$field}.", 422);
                $result[$field] = $value;
            }
        }
        return $result;
    }

    public function status(string $table, array $body): string
    {
        $choices = in_array($table, ['assets', 'asset_instances'], TRUE) ? ['active', 'inactive', 'retired'] : ['active', 'inactive'];
        if (array_keys($body) !== ['status'] || !in_array($body['status'], $choices, TRUE)) throw new DomainException('Provide a supported status only.', 422);
        return $body['status'];
    }
}
