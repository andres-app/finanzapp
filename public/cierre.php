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
function close_period_label(string $period): string{
    $months=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    if(!preg_match('/^(\d{4})-(\d{2})$/',$period,$m))return $period;
    $month=(int)$m[2];
    return ($months[$month]??$period).' '.$m[1];
}
function close_commitment_totals(array $snapshot): array{
    $summary=$snapshot['summary']??[];
    if(array_key_exists('committed_total',$summary) && array_key_exists('paid_total',$summary)){
        return [
            'committed'=>(float)($summary['committed_total']??0),
            'paid'=>(float)($summary['paid_total']??0),
            'pending'=>(float)($summary['pending_total']??0),
        ];
    }
    $committed=0.0;$paid=0.0;$pending=0.0;
    foreach(($snapshot['payments']??[]) as $payment){
        $status=(string)($payment['status']??'pending');
        if($status==='skipped')continue;
        $committed+=(float)($payment['amount']??0);
        $paid+=(float)($payment['paid_amount']??0);
        $pending+=(float)($payment['remaining_amount']??0);
    }
    return ['committed'=>$committed,'paid'=>$paid,'pending'=>$pending];
}
$periodLabel=close_period_label($period);
$currentCommitments=close_commitment_totals($current);
$savedCommitments=$saved?close_commitment_totals($saved):['committed'=>0.0,'paid'=>0.0,'pending'=>0.0];
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
   <?php if($closed):?><span class="close-status-kicker">▣ CERRADO</span><h2><?=e($periodLabel)?></h2><p>Cerrado por <?=e($closure['closed_by_name']?:'Usuario')?> el <?=e(date('d/m/Y H:i',strtotime($closure['closed_at'])))?>.</p>
   <?php elseif($closure):?><span class="close-status-kicker">↺ REABIERTO</span><h2><?=e($periodLabel)?></h2><p>La fotografía anterior se conserva; puedes volver a cerrar el mes cuando termines tus correcciones.</p>
   <?php else:?><span class="close-status-kicker">● ABIERTO</span><h2><?=e($periodLabel)?></h2><p>Cuando cierres el mes se guardarán sus principales cifras y compromisos como referencia histórica.</p><?php endif;?>
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
   <div class="close-metrics"><p><span>Ingresos</span><b><?=e(close_money($saved['summary']['income']??0))?></b></p><p><span>Gastos</span><b><?=e(close_money($saved['summary']['expense']??0))?></b></p><p><span>Neto</span><b><?=e(close_money($saved['summary']['balance']??0))?></b></p><p><span>Pendiente</span><b><?=e(close_money($savedCommitments['pending']))?></b></p></div>
   <div class="close-commitment-snapshot"><span>Comprometido <b><?=e(close_money($savedCommitments['committed']))?></b></span><span>Pagado <b><?=e(close_money($savedCommitments['paid']))?></b></span><span>Pendiente <b><?=e(close_money($savedCommitments['pending']))?></b></span></div>
  </section>
  <section class="close-panel <?=$changedAfterClose?'has-changes':''?>">
   <div class="close-panel-head"><div><span class="eyebrow">ESTADO ACTUAL</span><h3><?= $changedAfterClose?'Cambió después del cierre':'Sin cambios' ?></h3></div><span>Ahora</span></div>
   <div class="close-metrics"><p><span>Ingresos</span><b><?=e(close_money($current['summary']['income']??0))?></b></p><p><span>Gastos</span><b><?=e(close_money($current['summary']['expense']??0))?></b></p><p><span>Neto</span><b><?=e(close_money($current['summary']['balance']??0))?></b></p><p><span>Pendiente</span><b><?=e(close_money($currentCommitments['pending']))?></b></p></div>
   <div class="close-commitment-snapshot"><span>Comprometido <b><?=e(close_money($currentCommitments['committed']))?></b></span><span>Pagado <b><?=e(close_money($currentCommitments['paid']))?></b></span><span>Pendiente <b><?=e(close_money($currentCommitments['pending']))?></b></span></div>
  </section>
 </div>
 <?php endif;?>

 <div class="close-detail-grid">
  <section class="table-card close-panel-table"><div class="table-card-head"><strong>Cuentas</strong><span>Fotografía actual</span></div><div class="close-simple-list"><?php foreach($current['accounts'] as $a):?><div><span><?=e($a['name'])?></span><b><?=e(close_money($a['balance']))?></b></div><?php endforeach;?></div></section>
  <section class="table-card close-panel-table">
   <div class="table-card-head"><strong>Compromisos de <?=e($periodLabel)?></strong><span><?=count($current['payments'])?></span></div>
   <div class="close-commitment-totals">
    <div><span>Comprometido</span><b><?=e(close_money($currentCommitments['committed']))?></b></div>
    <div><span>Pagado</span><b><?=e(close_money($currentCommitments['paid']))?></b></div>
    <div><span>Pendiente</span><b><?=e(close_money($currentCommitments['pending']))?></b></div>
   </div>
   <div class="close-simple-list close-payment-list">
    <?php foreach($current['payments'] as $p):
      $paymentStatus=(string)($p['status']??'pending');
      $paymentAmount=(float)($p['amount']??0);
      $paymentPaid=(float)($p['paid_amount']??0);
      $paymentRemaining=(float)($p['remaining_amount']??0);
    ?>
    <div class="close-payment-row">
     <span class="close-payment-info"><b><?=e($p['name'])?></b><small><?=e(close_status_label($paymentStatus))?> · vence <?=e(date('d/m',strtotime($p['due_date'])))?></small></span>
     <span class="close-payment-values">
      <b><?=e(close_money($paymentAmount))?></b>
      <?php if($paymentStatus==='skipped'):?><small>Omitido este mes</small>
      <?php elseif($paymentStatus==='paid'):?><small>Pagado <?=e(close_money($paymentPaid))?> · Pendiente S/ 0.00</small>
      <?php elseif($paymentStatus==='partial'):?><small>Pagado <?=e(close_money($paymentPaid))?> · Pendiente <?=e(close_money($paymentRemaining))?></small>
      <?php else:?><small>Pagado <?=e(close_money($paymentPaid))?> · Pendiente <?=e(close_money($paymentRemaining))?></small><?php endif;?>
     </span>
    </div>
    <?php endforeach;?>
    <?php if(!$current['payments']):?><div class="empty">Sin pagos fijos en este mes.</div><?php endif;?>
   </div>
  </section>
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
