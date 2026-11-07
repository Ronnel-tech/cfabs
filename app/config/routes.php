<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

// Campus API. All protected actions enforce current database permissions.
$router->post('/api/auth/login', 'Auth::login');
$router->post('/api/auth/refresh', 'Auth::refresh');
$router->post('/api/auth/logout', 'Auth::logout');
$router->get('/api/auth/me', 'Auth::me');
$router->get('/api/auth/password-reset/config', 'Password_reset::configuration');
$router->post('/api/auth/password-reset/request', 'Password_reset::request');
$router->post('/api/auth/password-reset/confirm', 'Password_reset::confirm');
$router->get('/api/calendar/events', 'Calendar::events');
$router->get('/api/qr/{identifier}', 'Qr::resolve');
$router->get('/api/asset-problem-assignees', 'Problem_reports::assignees');
$router->get('/api/asset-problem-reports', 'Problem_reports::index');
$router->post('/api/asset-problem-reports', 'Problem_reports::create');
$router->get('/api/asset-problem-reports/{id}', 'Problem_reports::show');
$router->get('/api/asset-problem-reports/{id}/photo', 'Problem_reports::photo');
$router->post('/api/asset-problem-reports/{id}/{action}', 'Problem_reports::change');
$router->get('/api/qr-labels/{kind}/{id}', 'Qr::label');
$router->post('/api/qr-labels/{kind}/{id}', 'Qr::change');
$router->get('/api/auth/google/config', 'Google_auth::configuration');
$router->get('/api/auth/google/link', 'Google_auth::status');
$router->post('/api/auth/google/challenge', 'Google_auth::challenge');
$router->post('/api/auth/google/login', 'Google_auth::login');
$router->post('/api/auth/google/link', 'Google_auth::link');
$router->post('/api/auth/google/unlink', 'Google_auth::unlink');
$router->get('/api/users', 'Accounts::index');
$router->get('/api/users/{id}', 'Accounts::show');
$router->post('/api/users', 'Accounts::create');
$router->patch('/api/users/{id}/status', 'Access::account_status');
$router->patch('/api/users/{id}', 'Access::account_profile');
$router->patch('/api/users/{id}/role', 'Access::account_role');
$router->patch('/api/users/{id}/department', 'Access::account_department');
$router->get('/api/roles', 'Accounts::roles');
$router->post('/api/roles', 'Access::role_create');
$router->patch('/api/roles/{id}', 'Access::role_update');
$router->get('/api/roles/{id}/permissions', 'Access::role_permissions');
$router->put('/api/roles/{id}/permissions', 'Access::replace_permissions');
$router->get('/api/permissions', 'Accounts::permissions');
$router->get('admin-bootstrap', 'Admin_bootstrap::create');
$router->get('reminder-dispatch', 'Reminder_dispatch::run');
$router->get('email-dispatch', 'Mail_dispatch::run');
$router->get('/api/facilities', 'Catalog::facilities');
$router->get('/api/assets', 'Catalog::assets');
$router->get('/api/departments', 'Catalog::departments');
$router->post('/api/departments', 'Catalog::department_create');
$router->patch('/api/departments/{id}', 'Catalog::department_update');
$router->patch('/api/departments/{id}/status', 'Catalog::department_status');
$router->get('/api/facility-categories', 'Catalog::facility_categories');
$router->post('/api/facility-categories', 'Catalog::facility_category_create');
$router->patch('/api/facility-categories/{id}', 'Catalog::facility_category_update');
$router->patch('/api/facility-categories/{id}/status', 'Catalog::facility_category_status');
$router->get('/api/asset-categories', 'Catalog::asset_categories');
$router->post('/api/asset-categories', 'Catalog::asset_category_create');
$router->patch('/api/asset-categories/{id}', 'Catalog::asset_category_update');
$router->patch('/api/asset-categories/{id}/status', 'Catalog::asset_category_status');
$router->get('/api/facilities/{id}', 'Catalog::facility_show');
$router->post('/api/facilities', 'Catalog::facility_create');
$router->patch('/api/facilities/{id}', 'Catalog::facility_update');
$router->patch('/api/facilities/{id}/status', 'Catalog::facility_status');
$router->get('/api/assets/{id}', 'Catalog::asset_show');
$router->post('/api/assets', 'Catalog::asset_create');
$router->patch('/api/assets/{id}', 'Catalog::asset_update');
$router->patch('/api/assets/{id}/status', 'Catalog::asset_status');
$router->get('/api/assets/{id}/instances', 'Catalog::instances');
$router->post('/api/assets/{id}/instances', 'Catalog::instance_create');
$router->patch('/api/assets/{id}/instances/{instance_id}', 'Catalog::instance_update');
$router->patch('/api/assets/{id}/instances/{instance_id}/status', 'Catalog::instance_status');
$router->get('/api/facilities/{id}/availability', 'Bookings::facility_availability');
$router->get('/api/assets/{id}/availability', 'Bookings::asset_availability');
$router->get('/api/facility-bookings', 'Bookings::facility_index');
$router->get('/api/asset-bookings', 'Bookings::asset_index');
$router->get('/api/facility-bookings/{id}', 'Bookings::facility_show');
$router->get('/api/asset-bookings/{id}', 'Bookings::asset_show');
$router->post('/api/facility-bookings', 'Bookings::facility_create');
$router->post('/api/asset-bookings', 'Bookings::asset_create');
$router->post('/api/facility-booking-series', 'Bookings::facility_series');
$router->post('/api/asset-booking-series', 'Bookings::asset_series');
$router->get('/api/booking-series/{id}', 'Bookings::series_show');
$router->patch('/api/facility-bookings/{id}/approve', 'Bookings::facility_approve');
$router->patch('/api/asset-bookings/{id}/approve', 'Bookings::asset_approve');
$router->patch('/api/facility-bookings/{id}/reject', 'Bookings::facility_reject');
$router->patch('/api/asset-bookings/{id}/reject', 'Bookings::asset_reject');
$router->patch('/api/facility-bookings/{id}/cancel', 'Bookings::facility_cancel');
$router->patch('/api/asset-bookings/{id}/cancel', 'Bookings::asset_cancel');
$router->post('/api/asset-bookings/{id}/release', 'Handovers::release');
$router->post('/api/asset-bookings/{id}/return', 'Handovers::receive');
$router->post('/api/asset-bookings/{id}/complete', 'Handovers::complete');
$router->get('/api/asset-bookings/{id}/handovers', 'Handovers::history');
$router->get('/api/asset-bookings/{id}/custody', 'Handovers::custody');
$router->post('/api/asset-handovers/{id}/inspect', 'Handovers::inspect');
$router->get('/api/notifications', 'Bookings::notifications');
$router->get('/api/dashboard', 'Reports::dashboard');
$router->get('/api/facility-maintenance', 'Maintenance::facilities');
$router->get('/api/asset-maintenance', 'Maintenance::assets');
$router->post('/api/facility-maintenance', 'Maintenance::facility_create');
$router->post('/api/asset-maintenance', 'Maintenance::asset_create');
$router->patch('/api/facility-maintenance/{id}/complete', 'Maintenance::facility_complete');
$router->patch('/api/asset-maintenance/{id}/complete', 'Maintenance::asset_complete');
$router->patch('/api/facility-maintenance/{id}/cancel', 'Maintenance::facility_cancel');
$router->patch('/api/asset-maintenance/{id}/cancel', 'Maintenance::asset_cancel');
$router->get('/api/reports/facilities', 'Reports::facilities');
$router->get('/api/reports/assets', 'Reports::assets');
$router->get('/api/reports/inventory', 'Reports::inventory');
$router->get('/api/audit-logs', 'Reports::audit');
$router->patch('/api/facility-bookings/{id}/complete', 'Reports::complete_facility');
$router->patch('/api/notifications/{id}/read', 'Bookings::read_notification');


// Migration Routes 

$router->get('create-migration/{migration_class}', 'MigrationController::create_migration'); 

$router->get('migrate', 'MigrationController::migrate'); 

$router->get('rollback', 'MigrationController::rollback'); 

$router->get('rollback-all', 'MigrationController::rollback_all'); 

$router->get('refresh', 'MigrationController::refresh'); 

$router->get('status', 'MigrationController::status'); 
