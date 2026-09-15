<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();verify_csrf();$d=request_json();
$period=trim((string)($d['period']??date('Y-m')));$action=trim((string)($d['action']??'close'));$notes=trim((string)($d['notes']??''));
if(!preg_match('/^\d{4}-\d{2}$/',$period))json_response(['ok'=>false,'message'=>'Mes no válido.'],422);
try{
    if($action==='close'){$row=MonthCloseService::close($uid,$period,$notes);json_response(['ok'=>true,'message'=>'Mes cerrado correctamente.','closure'=>$row]);}
    if($action==='reopen'){MonthCloseService::reopen($uid,$period);json_response(['ok'=>true,'message'=>'Mes reabierto.']);}
    json_response(['ok'=>false,'message'=>'Acción no válida.'],422);
}catch(DomainException $e){json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){
    $code='CIE-API-'.strtoupper(substr(hash('sha256',$e->getMessage()),0,8));
    error_log('[MiDinero month_close '.$code.'] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'message'=>'No se pudo actualizar el cierre mensual. Código: '.$code],500);
}
