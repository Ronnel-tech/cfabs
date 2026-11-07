<?php
// Liveness only: no database, secrets or framework diagnostics.
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo '{"status":"ok"}';
