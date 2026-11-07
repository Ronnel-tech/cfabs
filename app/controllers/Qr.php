<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Qr extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access', 'qr_service']);
        header('Cache-Control: no-store');
    }

    public function resolve($identifier)
    {
        $actor = $this->account_access->require_user();
        $this->respond(fn() => $this->qr_service->resolve($identifier, $actor));
    }

    public function label($kind, $id)
    {
        $actor = $this->account_access->require_user();
        $this->respond(fn() => $this->qr_service->label($kind, $this->booking_rules->positive_id($id), $actor));
    }

    public function change($kind, $id)
    {
        $actor = $this->account_access->require_user();
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->qr_service->change($kind, $this->booking_rules->positive_id($id), $body, $actor));
    }

    private function respond(callable $action)
    {
        try { $data = $action(); }
        catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        $this->api->respond(['success' => TRUE, 'data' => $data]);
    }
}
