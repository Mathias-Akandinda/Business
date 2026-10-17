<?php
require __DIR__.'/bootstrap.php'; require_admin();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit; }
csrf(); $_SESSION=[]; session_destroy(); header('Location: admin_login.php');
