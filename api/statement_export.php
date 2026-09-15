<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
require_once __DIR__.'/../app/StatementExportService.php';
$period=(string)($_GET['period']??date('Y-m'));
$accountId=max(0,(int)($_GET['account_id']??0));
$format=strtolower((string)($_GET['format']??'pdf'));
try{
    $data=StatementExportService::build($uid,$period,$accountId);
    $ctx=current_household();$owner=(string)($ctx['household_name']??(current_user()['name']??'Mi Dinero'));
    $slug=preg_replace('/[^A-Za-z0-9_-]+/','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$data['account_name'])?:'cuentas');
    if($format==='excel'||$format==='xls'){
        $body=StatementExportService::excelXml($data,$owner);
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="estado_cuenta_'.$period.'_'.$slug.'.xls"');
        header('Cache-Control: private, no-store, max-age=0');
        echo $body;exit;
    }
    $body=StatementExportService::pdf($data,$owner);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="estado_cuenta_'.$period.'_'.$slug.'.pdf"');
    header('Content-Length: '.strlen($body));
    header('Cache-Control: private, no-store, max-age=0');
    echo $body;exit;
}catch(Throwable $e){
    error_log('[MiDinero estado cuenta] '.$e->getMessage());
    http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'No se pudo generar el estado de cuenta. Intenta nuevamente.';
}
