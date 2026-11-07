<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Mail_configuration {
    public function enabled(): bool { return getenv('MAIL_ENABLED') === '1'; }
    public function sender(): string
    {
        $address = trim(getenv('MAIL_FROM_ADDRESS') ?: '');
        $name = trim(getenv('MAIL_FROM_NAME') ?: 'CFABS');
        if (!self::address($address) || strlen($name) > 80 || preg_match('/[\r\n<>"\\\\]/', $name)) throw new RuntimeException('Configure a valid MAIL_FROM_ADDRESS and MAIL_FROM_NAME.');
        return $name . ' <' . $address . '>';
    }
    public static function address(string $value): bool
    {
        return strlen($value) <= 255 && !preg_match('/[\r\n\x00]/', $value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== FALSE;
    }
    public function app_url(): string
    {
        $url = rtrim(trim(getenv('MAIL_APP_URL') ?: ''), '/');
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !filter_var($url,FILTER_VALIDATE_URL) || preg_match('/[\r\n\x00]/',$url)
            || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost','127.0.0.1','[::1]'],TRUE)))) {
            throw new RuntimeException('Configure MAIL_APP_URL with HTTPS, or HTTP localhost for development.');
        }
        return $url;
    }
    public function validate(bool $sending = FALSE): void
    {
        if ((getenv('MAIL_PROVIDER') ?: 'resend') !== 'resend') throw new RuntimeException('MAIL_PROVIDER must be resend.');
        $this->sender(); $this->app_url();
        if (strlen((string) config_item('encryption_key')) < 32) throw new RuntimeException('A strong APP_KEY is required for email.');
        if ($sending) {
            $key = getenv('MAIL_API_KEY') ?: '';
            if (!$this->enabled() || trim($key) === '' || preg_match('/[\r\n\x00]/',$key)) throw new RuntimeException('Email sending is disabled or its API key is not configured.');
        }
    }
    public function ready(): bool
    {
        try { $this->validate(TRUE); return TRUE; } catch (RuntimeException $error) { return FALSE; }
    }
}
