<?php
// Shared application configuration. Override with server environment variables.
return [
 'host' => getenv('DB_HOST') ?: '127.0.0.1',
 'port' => getenv('DB_PORT') ?: '3306',
 'database' => getenv('DB_NAME') ?: 'tembonet',
 'username' => getenv('DB_USER') ?: 'root',
 'password' => getenv('DB_PASSWORD') ?: '',
 'sms_username' => getenv('SMS_USERNAME') ?: '',
 'sms_key' => getenv('SMS_API_KEY') ?: '',
];
