<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Kampala');
function db(): PDO {
 static $pdo;
 if (!$pdo) {
  $c = require __DIR__.'/config.php';
  $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
  $pdo->exec("SET time_zone = '+03:00'");
 }
 return $pdo;
}
function start_session(): void {
 if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
  session_start();
 }
 $_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
}
function csrf(): void {
 start_session();
 if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
  http_response_code(403); exit('Invalid security token. Reload the page.');
 }
}
function require_admin(): void {
 start_session();
 if (empty($_SESSION['admin']) || time() - ($_SESSION['last_seen'] ?? 0) > 1800) {
  unset($_SESSION['admin']);
  if (isset($_GET['action'])) { json_response(['success'=>false,'message'=>'Please sign in again.'],401); }
  header('Location: admin_login.php'); exit;
 }
 $_SESSION['last_seen'] = time();
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function json_response(array $data, int $status=200): void {
 http_response_code($status); header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store'); echo json_encode($data); exit;
}
function sendSMS($phone, $message): bool {
 $c = require __DIR__.'/config.php';
 if (!$c['sms_key'] || !$c['sms_username'] || !function_exists('curl_init')) return false;
 $ch = curl_init('https://api.africastalking.com/version1/messaging');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['username'=>$c['sms_username'],'to'=>$phone,'message'=>$message]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Accept: application/json','apiKey: '.$c['sms_key']]]);
 $result = json_decode((string)curl_exec($ch),true); curl_close($ch);
 $recipients = $result['SMSMessageData']['Recipients'] ?? [];
 return count($recipients)>0 && (int)($recipients[0]['statusCode'] ?? 0) === 101;
}
set_exception_handler(function(Throwable $e): void {
 error_log((string)$e);
 if (isset($_GET['action']) || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'voucher_request.php') json_response(['success'=>false,'message'=>'Service unavailable. Check database installation and server logs.'],503);
 http_response_code(503); echo 'Service unavailable. Follow README.md to configure the database.';
});
