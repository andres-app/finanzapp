<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();FinanceSchema::ensure($uid);require __DIR__.'/../app/layout.php';
$period=$_GET['period']??date('Y-m');[$s,$e2]=month_range($period);

$st=db()->prepare("SELECT t.*,c.name category,c.icon,co.name concept,a.name account,a.icon account_icon,f.name fund,f.icon fund_icon,u.name actor_name FROM transactions t JOIN categories c ON c.id=t.category_id LEFT JOIN concepts co ON co.id=t.concept_id LEFT JOIN financial_accounts a ON a.id=t.account_id LEFT JOIN funds f ON f.id=t.fund_id LEFT JOIN users u ON u.id=COALESCE(t.created_by_user_id,t.user_id) WHERE t.user_id=? AND t.occurred_at>=? AND t.occurred_at<? ORDER BY t.occurred_at DESC");
$st->execute([$uid,$s,$e2]);$rows=$st->fetchAll();

// Las transferencias creadas internamente por Ahorro ya quedan representadas
// como un movimiento de Ahorro; no las repetimos en la tabla de transferencias.
$tr=db()->prepare("SELECT tr.*,a1.name from_name,a1.icon from_icon,a2.name to_name,a2.icon to_icon,u.name actor_name FROM account_transfers tr JOIN financial_accounts a1 ON a1.id=tr.from_account_id JOIN financial_accounts a2 ON a2.id=tr.to_account_id LEFT JOIN users u ON u.id=COALESCE(tr.created_by_user_id,tr.user_id) WHERE tr.user_id=? AND tr.occurred_at>=? AND tr.occurred_at<? AND (tr.description IS NULL OR tr.description NOT LIKE 'Ahorro protegido%') ORDER BY tr.occurred_at DESC");
$tr->execute([$uid,$s,$e2]);$transfers=$tr->fetchAll();

$ad=db()->prepare("SELECT ad.*,a.name account,a.icon account_icon,u.name actor_name FROM account_adjustments ad JOIN financial_accounts a ON a.id=ad.account_id LEFT JOIN users u ON u.id=COALESCE(ad.created_by_user_id,ad.user_id) WHERE ad.user_id=? AND ad.occurred_at>=? AND ad.occurred_at<? ORDER BY ad.occurred_at DESC");
$ad->execute([$uid,$s,$e2]);$adjustments=$ad->fetchAll();

try{$savingsRows=FinanceService::savingsHistory($uid,500,$s,$e2);}catch(Throwable $e){$savingsRows=[];error_log('[MiDinero movimientos ahorro] '.$e->getMessage());}

// Fondos operativos: separar/liberar dinero también es una operación interna trazable.
$fundRows=[];
try{
    $fq=db()->prepare("SELECT fa.id,fa.fund_id,fa.amount,fa.occurred_at,fa.note,fa.source_transaction_id,
        f.name fund_name,f.icon fund_icon,
        a.name source_account,a.icon source_account_icon,u.name actor_name
        FROM fund_allocations fa
        JOIN funds f ON f.id=fa.fund_id AND f.user_id=fa.user_id
        LEFT JOIN transactions t ON t.id=fa.source_transaction_id AND t.user_id=fa.user_id
        LEFT JOIN financial_accounts a ON a.id=t.account_id AND a.user_id=fa.user_id
        LEFT JOIN users u ON u.id=COALESCE(fa.created_by_user_id,fa.user_id)
        WHERE fa.user_id=? AND fa.occurred_at>=? AND fa.occurred_at<?
        ORDER BY fa.occurred_at DESC,fa.id DESC");
    $fq->execute([$uid,$s,$e2]);
    foreach($fq->fetchAll() as $fr){
        $isSavings=false;
        try{$isSavings=SavingsSchema::isSavingsFund($uid,(int)$fr['fund_id']);}catch(Throwable $ignored){$isSavings=(strtolower(trim((string)$fr['fund_name']))==='ahorro'||str_starts_with((string)$fr['fund_name'],'__SAV7__'));}
        if(!$isSavings)$fundRows[]=$fr;
    }
}catch(Throwable $e){error_log('[MiDinero movimientos fondos] '.$e->getMessage());}

$income=0;$expense=0;
foreach($rows as $r){if($r['type']==='income')$income+=(float)$r['amount'];else$expense+=(float)$r['amount'];}

// Unificamos ingresos/egresos y ahorro solo para la lectura cronológica.
$activity=[];
foreach($rows as $r){
    $activity[]=['kind'=>'transaction','when'=>$r['occurred_at'],'row'=>$r];
}
foreach($savingsRows as $r){
    $activity[]=['kind'=>'savings','when'=>$r['occurred_at'],'row'=>$r];
}
foreach($fundRows as $r){
    $activity[]=['kind'=>'fund','when'=>$r['occurred_at'],'row'=>$r];
}
foreach($transfers as $r){
    $activity[]=['kind'=>'transfer','when'=>$r['occurred_at'],'row'=>$r];
}
usort($activity,function($a,$b){return strcmp($b['when'],$a['when']);});

page_top('Movimientos','movimientos');
?>
<div class="page-wrap finance-page">
 <div class="page-head finance-page-head"><div><span class="eyebrow">HISTORIAL</span><h1>Movimientos</h1><p>Ingresos y egresos afectan tu patrimonio. Ahorro y transferencias solo organizan tu propio dinero.</p></div><form class="page-filter"><input type="month" name="period" value="<?=e($period)?>" onchange="this.form.submit()"></form></div>
 <div class="movement-summary"><div><span>Ingresos</span><strong class="amount-in">+ S/ <?=number_format($income,2)?></strong></div><div><span>Gastos</span><strong class="amount-out">− S/ <?=number_format($expense,2)?></strong></div><div><span>Neto del mes</span><strong>S/ <?=number_format($income-$expense,2)?></strong></div><div><span>Movimientos internos</span><strong><?=count($transfers)+count($adjustments)+count($savingsRows)+count($fundRows)?></strong></div></div>
 <div class="table-card"><div class="table-card-head"><strong>Actividad del mes</strong><span><?=count($activity)?> movimientos</span></div><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Cuenta</th><th>Categoría / concepto</th><th>Fondo</th><th>Detalle</th><th class="right">Monto</th></tr></thead><tbody>
 <?php foreach($activity as $item): $r=$item['row']; ?>
   <?php if($item['kind']==='transaction'): ?>
   <tr><td><?=e(date('d/m/Y H:i',strtotime($r['occurred_at'])))?></td><td><span class="badge <?=$r['type']==='income'?'income':'expense'?>"><?=$r['type']==='income'?'Ingreso':'Egreso'?></span></td><td><?=e(($r['account_icon']?:'🏦').' '.($r['account']?:'Cuenta principal'))?></td><td><b><?=e(($r['icon']?:'•').' '.$r['category'])?></b><small class="table-sub"><?=e($r['concept']?:'Sin concepto')?></small></td><td><?= $r['fund'] ? e(($r['fund_icon']?:'◎').' '.$r['fund']) : '<span class="muted">Sin fondo</span>' ?></td><td><?=e($r['description']?:'-')?><?=$r['is_ant_expense']?' <span class="ant-tag">🐜 hormiga</span>':''?><small class="table-sub actor-sub">Registrado por <?=e($r['actor_name']?:'Usuario')?></small></td><td class="right"><strong class="<?=$r['type']==='income'?'amount-in':'amount-out'?>"><?=$r['type']==='income'?'+':'-'?> S/ <?=number_format((float)$r['amount'],2)?></strong></td></tr>
   <?php elseif($item['kind']==='savings'): $deposit=$r['movement_type']==='deposit'; $same=$r['from_account']===$r['to_account']; ?>
   <tr class="savings-activity-row"><td><?=e(date('d/m/Y H:i',strtotime($r['occurred_at'])))?></td><td><span class="badge savings"><?=$deposit?'Ahorro':'Retiro ahorro'?></span></td><td><?=e('🏦 '.($deposit?$r['to_account']:$r['from_account']))?></td><td><b>🐷 <?=e($r['goal_name'])?></b><small class="table-sub"><?=$deposit?'Dinero protegido':'Dinero liberado'?></small></td><td><span class="savings-fund-label">🔒 Ahorro</span></td><td><?= $deposit ? ($same?'Se quedó en la misma cuenta':'Movido de '.e($r['from_account']).' a '.e($r['to_account'])) : ($same?'Liberado en la misma cuenta':'Liberado hacia '.e($r['to_account'])) ?><?=!empty($r['note'])?' · '.e($r['note']):''?><small class="table-sub actor-sub">Registrado por <?=e($r['actor_name']??'Usuario')?></small></td><td class="right"><strong class="savings-amount <?=$deposit?'deposit':'withdrawal'?>"><?=$deposit?'+':'−'?> S/ <?=number_format((float)$r['amount'],2)?></strong><small class="table-sub">No afecta ingresos/gastos</small></td></tr>
   <?php elseif($item['kind']==='fund'): $separated=((float)$r['amount'])>=0; ?>
   <tr class="fund-activity-row"><td><?=e(date('d/m/Y H:i',strtotime($r['occurred_at'])))?></td><td><span class="badge fund-move"><?=$separated?'Fondo':'Liberar fondo'?></span></td><td><?= $r['source_account'] ? e(($r['source_account_icon']?:'🏦').' '.$r['source_account']) : '<span class="muted">No mueve cuenta</span>' ?></td><td><b><?=e(($r['fund_icon']?:'◎').' '.$r['fund_name'])?></b><small class="table-sub"><?=$separated?'Dinero separado':'Dinero liberado'?></small></td><td><?=e(($r['fund_icon']?:'◎').' '.$r['fund_name'])?></td><td><?=e($r['note']?:($separated?'Separado para este fondo':'Devuelto a dinero libre'))?><small class="table-sub actor-sub">Registrado por <?=e($r['actor_name']?:'Usuario')?></small></td><td class="right"><strong class="fund-move-amount <?=$separated?'deposit':'withdrawal'?>"><?=$separated?'+':'−'?> S/ <?=number_format(abs((float)$r['amount']),2)?></strong><small class="table-sub">No afecta ingresos/gastos</small></td></tr>
   <?php else: ?>
   <tr class="transfer-activity-row"><td><?=e(date('d/m/Y H:i',strtotime($r['occurred_at'])))?></td><td><span class="badge transfer-move">Transferencia</span></td><td><?=e(($r['from_icon']?:'🏦').' '.$r['from_name'])?></td><td><b>↔ Movimiento entre cuentas</b><small class="table-sub"><?=e($r['from_name'].' → '.$r['to_name'])?></small></td><td><span class="muted">No aplica</span></td><td><?=e($r['description']?:'Transferencia interna')?><small class="table-sub actor-sub">Registrado por <?=e($r['actor_name']?:'Usuario')?></small></td><td class="right"><strong class="transfer-move-amount">S/ <?=number_format((float)$r['amount'],2)?></strong><small class="table-sub">No afecta ingresos/gastos</small></td></tr>
   <?php endif; ?>
 <?php endforeach; ?>
 <?php if(!$activity):?><tr><td colspan="7" class="empty">Todavía no hay actividad en este mes.</td></tr><?php endif;?></tbody></table></div></div>
 <?php if($adjustments):?><div class="table-card transfer-table"><div class="table-card-head"><strong>Ajustes de saldo</strong><span>Conciliaciones; no son ingresos ni gastos</span></div><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Cuenta</th><th>Motivo</th><th class="right">Ajuste</th></tr></thead><tbody><?php foreach($adjustments as $r):?><tr><td><?=e(date('d/m/Y H:i',strtotime($r['occurred_at'])))?></td><td><?=e(($r['account_icon']?:'🏦').' '.$r['account'])?></td><td><?=e($r['note']?:'Ajuste de saldo')?></td><td class="right"><strong class="<?=$r['amount']>=0?'amount-in':'amount-out'?>"><?=$r['amount']>=0?'+':'−'?> S/ <?=number_format(abs((float)$r['amount']),2)?></strong></td></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
</div>
<script src="<?=e(app_url('assets/js/realtime-view.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/realtime-view.js'))?>"></script>
<?php page_bottom(); ?>
