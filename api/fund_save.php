<?php
require __DIR__.'/../app/bootstrap.php';$uid=require_auth();verify_csrf();FinanceSchema::ensure($uid);$d=request_json();
$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));$icon=trim((string)($d['icon']??''))?:'💰';$color=trim((string)($d['color']??''))?:'#6b7280';$target=max(0,(float)($d['target_amount']??0));
if($name==='')json_response(['ok'=>false,'message'=>'Escribe el nombre del fondo.'],422);
if($id){$st=db()->prepare('UPDATE funds SET name=?,icon=?,color=?,target_amount=? WHERE id=? AND user_id=? AND active=1');$st->execute([$name,$icon,$color,$target,$id,$uid]);}
else{$st=db()->prepare('INSERT INTO funds(user_id,name,icon,color,target_amount) VALUES(?,?,?,?,?)');$st->execute([$uid,$name,$icon,$color,$target]);$id=(int)db()->lastInsertId();}
emit_event($uid,'fund_changed',['id'=>$id]);json_response(['ok'=>true,'id'=>$id]);
