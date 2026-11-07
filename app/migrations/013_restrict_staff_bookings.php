<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Restrict_staff_bookings {
    public function up()
    {
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $lava->db->raw("DELETE rp FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
            WHERE r.role_name IN ('system_admin','booking_admin','asset_custodian')
            AND p.permission_key IN ('facility_booking.create','asset_booking.create')");
    }
    public function down()
    {
        // Never silently restore staff booking privileges on rollback.
    }
}
