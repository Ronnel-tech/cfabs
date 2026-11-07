<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Account_rules {
    public function new_account(array $input, bool $require_names = TRUE): array
    {
        $username = $input['username'] ?? null;
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;
        if (!is_string($username) || !preg_match('/\A[A-Za-z0-9_.-]{3,100}\z/', $username)) {
            throw new InvalidArgumentException('Username must contain 3–100 letters, digits, dots, underscores or hyphens.');
        }
        if (!is_string($email) || strlen($email) > 255 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email is required.');
        }
        $password = $this->password($password);
        $result = ['username' => $username, 'email' => trim($email), 'password' => $password];
        foreach (['first_name', 'last_name'] as $field) {
            $value = $input[$field] ?? null;
            if ($value === null && !$require_names) {
                $result[$field] = null;
                continue;
            }
            if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > 100) {
                throw new InvalidArgumentException("{$field} is required and must be at most 100 bytes.");
            }
            $result[$field] = trim($value);
        }
        return $result;
    }
    public function password($password): string
    {
        // PASSWORD_DEFAULT currently uses bcrypt; reject truncation and NUL bytes.
        if (!is_string($password) || strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0")) {
            throw new InvalidArgumentException('Password must contain 12–72 bytes without NUL characters.');
        }
        return $password;
    }
}
