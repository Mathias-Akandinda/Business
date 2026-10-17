<?php
require __DIR__.'/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'message'=>'POST required.'],405);
$phone = trim((string)($_POST['phone'] ?? ''));
$package = (string)($_POST['package'] ?? '');
$plans = ['24 HOURS'=>1000,'1 WEEK'=>6000,'1 MONTH'=>20000];
if (!preg_match('/^(07[0-9]{8}|2567[0-9]{8})$/',$phone) || !isset($plans[$package])) json_response(['success'=>false,'message'=>'Select a valid package and Uganda phone number.'],422);
$price = $plans[$package];
if ((string)($_POST['price'] ?? '') !== (string)$price) json_response(['success'=>false,'message'=>'Package price mismatch. Reload and try again.'],422);
if ($phone[0]==='0') $phone='256'.substr($phone,1);
$p=db();
// A per-phone advisory lock makes duplicate checks atomic across concurrent submissions.
$lock=$p->prepare('SELECT GET_LOCK(?,5)'); $lock->execute(['request_'.$phone]);
if ((int)$lock->fetchColumn()!==1) json_response(['success'=>false,'message'=>'Please retry shortly.'],429);
try {
 $s=$p->prepare("SELECT COUNT(*) FROM voucher_requests WHERE phone=? AND created_at>DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $s->execute([$phone]);
 if ((int)$s->fetchColumn()>0) json_response(['success'=>false,'message'=>'You recently submitted a request. Please wait for review.'],429);
 $id=strtoupper(bin2hex(random_bytes(8)));
 $p->beginTransaction();
 $s=$p->prepare('INSERT INTO voucher_requests(request_id,phone,package,price) VALUES (?,?,?,?)'); $s->execute([$id,$phone,$package,$price]);
 $s=$p->prepare("INSERT INTO system_logs(log_type,reference_id,user_identifier,action_description,ip_address) VALUES ('voucher_request',?,?,?,?)");
 $s->execute([$id,$phone,'New payment request',$_SERVER['REMOTE_ADDR'] ?? null]);
 $p->commit();
} finally { $s=$p->prepare('SELECT RELEASE_LOCK(?)'); $s->execute(['request_'.$phone]); }
json_response(['success'=>true,'request_id'=>$id,'message'=>'Request recorded for manual payment verification.','sms_sent'=>false]);
