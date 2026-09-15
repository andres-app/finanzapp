<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
try{json_response(['ok'=>true,'alerts'=>AlertService::current($uid),'generated_at'=>date('c')]);}
catch(Throwable $e){error_log('[MiDinero alerts] '.$e->getMessage());json_response(['ok'=>false,'message'=>'No se pudieron cargar las alertas.','alerts'=>[]],500);}
