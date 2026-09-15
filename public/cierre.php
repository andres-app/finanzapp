<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();require __DIR__.'/../app/layout.php';
$period=trim((string)($_GET['period']??date('Y-m')));if(!preg_match('/^\d{4}-\d{2}$/',$period))$period=date('Y-m');
$loadError=null;$errorCode=null;$current=['summary'=>[],'accounts'=>[],'funds'=>[],'payments'=>[]];$closure=null;$currentHash='';
try{
    $current=MonthCloseService::snapshot($uid,$period);
    $currentHash=MonthCloseService::hash($current);
}catch(Throwable $e){
    $errorCode='CIE-'.strtoupper(substr(hash('sha256',$e->getMessage()),0,8));
    error_log('[MiDinero cierre snapshot '.$errorCode.'] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    $loadError='No se pudieron leer las cifras del mes.';
}
try{
    $closure=MonthCloseService::get($uid,$period);
}catch(Throwable $e){
    $code='CIE-'.strtoupper(substr(hash('sha256',$e->getMessage()),0,8));
    error_log('[MiDinero cierre metadata '.$code.'] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    if(!$loadError){$loadError='Las cifras cargaron, pero no se pudo leer el cierre guardado.';$errorCode=$code;}
}
$closed=$closure&&!empty($closure['is_closed']);$changedAfterClose=$closed&&((string)($closure['snapshot_hash']??'')!==$currentHash);
$saved=$closure['snapshot']??null;$canManage=HouseholdSchema::canManage(actual_user_id());
function close_money($v): string{return 'S/ '.number_format((float)$v,2);}
function close_status_label(string $status): string{
    switch($status){case 'paid': return 'Pagado';case 'partial': return 'Parcial';case 'skipped': return 'Omitido';default: return 'Pendiente';}
}
page_top('Cierre mensual','cierre');
?>
<div class="page-wrap close-page">
 <?php if($loadError):?>
 <div class="close-change-warning" style="margin-bottom:18px">
   <strong>No pudimos cargar el cierre mensual.</strong>
   <span><?=e($loadError)?> Código: <?=e($errorCode)?>. No se ha alterado ningún movimiento. Si el aviso continúa, el código permite ubicar el error exacto en el registro del servidor.</span>
 </div>
 <?php endif;?>
 <div class="page-head close-page-head">
   <div><span class="eyebrow">CONTROL MENSUAL</span><h1>Cierre mensual</h1><p>Guarda una fotografía del mes sin borrar ni alterar tus movimientos.</p></div>
   <form data-spa-filter method="get" action="<?=e(app_url('cierre'))?>"><input type="month" name="period" value="<?=e($period)?>"></form>
 </div>

 <section class="close-status-card <?=$closed?'is-closed':($closure?'is-reopened':'is-open')?>">
  <div>
   <?php if($closed):?><span class="close-status-kicker">▣ CERRADO</span><h2><?=e($period)?></h2><p>Cerrado por <?=e($closure['closed_by_name']?:'Usuario')?> el <?=e(date('d/m/Y H:i',strtotime($closure['closed_at'])))?>.</p>
   <?php elseif($closure):?><span class="close-status-kicker">↺ REABIERTO</span><h2><?=e($period)?></h2><p>La fotografía anterior se conserva; puedes volver a cerrar el mes cuando termines tus correcciones.</p>
   <?php else:?><span class="close-status-kicker">● ABIERTO</span><h2><?=e($period)?></h2><p>Cuando cierres el mes se guardarán sus principales cifras y compromisos como referencia histórica.</p><?php endif;?>
  </div>
  <div class="close-status-actions">
   <a class="btn" href="<?=e(app_url('actividad?period='.$period))?>">Ver actividad</a>
   <?php if(!$loadError && $closed&&$canManage):?><button class="btn" type="button" data-month-action="reopen" data-period="<?=e($period)?>">Reabrir mes</button>
   <?php elseif(!$loadError && !$closed):?><button class="btn primary" type="button" data-month-action="close" data-period="<?=e($period)?>">Cerrar mes</button><?php endif;?>
  </div>
 </section>

 <?php if($changedAfterClose):?><div class="close-change-warning"><strong>Hay cambios posteriores al cierre.</strong><span>La fotografía guardada no se modificó. Puedes comparar ambos estados o reabrir y cerrar nuevamente si deseas actualizarla.</span></div><?php endif;?>

 <div class="close-summary-grid">
  <div><span>Ingresos</span><strong class="amount-in"><?=e(close_money($current['summary']['income']??0))?></strong></div>
  <div><span>Gastos</span><strong class="amount-out"><?=e(close_money($current['summary']['expense']??0))?></strong></div>
  <div><span>Neto del mes</span><strong><?=e(close_money($current['summary']['balance']??0))?></strong></div>
  <div><span>Saldo al cierre</span><strong><?=e(close_money($current['summary']['closing_balance']??0))?></strong></div>
 </div>

 <?php if($saved):?>
 <div class="close-compare-grid">
  <section class="close-panel">
   <div class="close-panel-head"><div><span class="eyebrow">FOTOGRAFÍA GUARDADA</span><h3>Estado al cerrar</h3></div><span><?=e(date('d/m/Y',strtotime($closure['closed_at'])))?></span></div>
   <div class="close-metrics"><p><span>Ingresos</span><b><?=e(close_money($saved['summary']['income']??0))?></b></p><p><span>Gastos</span><b><?=e(close_money($saved['summary']['expense']??0))?></b></p><p><span>Neto</span><b><?=e(close_money($saved['summary']['balance']??0))?></b></p><p><span>Pendiente</span><b><?=e(close_money($saved['summary']['pending_total']??0))?></b></p></div>
  </section>
  <section class="close-panel <?=$changedAfterClose?'has-changes':''?>">
   <div class="close-panel-head"><div><span class="eyebrow">ESTADO ACTUAL</span><h3><?= $changedAfterClose?'Cambió después del cierre':'Sin cambios' ?></h3></div><span>Ahora</span></div>
   <div class="close-metrics"><p><span>Ingresos</span><b><?=e(close_money($current['summary']['income']??0))?></b></p><p><span>Gastos</span><b><?=e(close_money($current['summary']['expense']??0))?></b></p><p><span>Neto</span><b><?=e(close_money($current['summary']['balance']??0))?></b></p><p><span>Pendiente</span><b><?=e(close_money($current['summary']['pending_total']??0))?></b></p></div>
  </section>
 </div>
 <?php endif;?>

 <div class="close-detail-grid">
  <section class="table-card close-panel-table"><div class="table-card-head"><strong>Cuentas</strong><span>Fotografía actual</span></div><div class="close-simple-list"><?php foreach($current['accounts'] as $a):?><div><span><?=e($a['name'])?></span><b><?=e(close_money($a['balance']))?></b></div><?php endforeach;?></div></section>
  <section class="table-card close-panel-table"><div class="table-card-head"><strong>Compromisos de <?=e($period)?></strong><span><?=count($current['payments'])?></span></div><div class="close-simple-list"><?php foreach($current['payments'] as $p):?><div><span><b><?=e($p['name'])?></b><small><?=e(close_status_label($p['status']))?> · vence <?=e(date('d/m',strtotime($p['due_date'])))?></small></span><b><?=e(close_money($p['remaining_amount']))?></b></div><?php endforeach;?><?php if(!$current['payments']):?><div class="empty">Sin pagos fijos en este mes.</div><?php endif;?></div></section>
 </div>
 <?php if($closure&&!empty($closure['notes'])):?><div class="close-notes"><span>Nota del cierre</span><p><?=e($closure['notes'])?></p></div><?php endif;?>
</div>

<div class="modal audit-modal" id="monthActionModal" aria-hidden="true">
 <div class="modal-card audit-modal-card"><button class="modal-x" type="button" data-month-close>×</button><span class="eyebrow" id="monthActionEyebrow">CIERRE MENSUAL</span><h3 id="monthActionTitle">Cerrar mes</h3><p id="monthActionText"></p>
 <form id="monthActionForm"><input type="hidden" name="action" id="monthActionType"><input type="hidden" name="period" id="monthActionPeriod"><label id="monthNotesLabel">Nota opcional<textarea name="notes" rows="3" maxlength="500" placeholder="Ej.: mes revisado y conciliado"></textarea></label><div class="modal-actions"><button class="btn" type="button" data-month-close>Cancelar</button><button class="btn primary" id="monthActionSubmit" type="submit">Confirmar</button></div></form>
 </div>
</div>
<script src="<?=e(app_url('assets/js/audit.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/audit.js'))?>"></script>
<?php page_bottom(); ?>
