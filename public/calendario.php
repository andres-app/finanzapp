<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
require __DIR__.'/../app/layout.php';
$period=$_GET['period']??date('Y-m');
if(!preg_match('/^\d{4}-\d{2}$/',$period))$period=date('Y-m');
$data=FinancialCalendarService::month($uid,$period);
$payments=$data['payments'];$incomes=$data['incomes'];
$months=['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
[$year,$month]=explode('-',$period);$monthLabel=$months[$month].' '.$year;
$prev=(new DateTimeImmutable($period.'-01'))->modify('-1 month')->format('Y-m');
$next=(new DateTimeImmutable($period.'-01'))->modify('+1 month')->format('Y-m');
$first=new DateTimeImmutable($period.'-01');
$gridStart=$first->modify('-'.((int)$first->format('N')-1).' days');
$events=[];
foreach($payments as $p){$events[$p['due_date']][]=['type'=>'payment','row'=>$p];}
foreach($incomes as $r){$events[$r['due_date']][]=['type'=>'income','row'=>$r];}
$pendingTotal=0;$paidTotal=0;$commitTotal=0;
foreach($payments as $p){if($p['status']!=='skipped')$commitTotal+=(float)$p['amount'];$paidTotal+=(float)$p['paid_amount'];if(in_array($p['status'],['pending','partial'],true))$pendingTotal+=(float)$p['remaining_amount'];}
$expectedIncome=array_sum(array_map(fn($r)=>(float)$r['expected_amount'],$incomes));
$bootstrap=['period'=>$period,'accounts'=>$data['accounts'],'funds'=>$data['funds'],'autoPay'=>(int)($_GET['pay']??0)];
page_top('Calendario financiero','calendario');
?>
<div class="page-wrap calendar-page">
  <div class="page-head calendar-head">
    <div><span class="eyebrow">AGENDA DE TU DINERO</span><h1>Calendario financiero</h1><p>Visualiza vencimientos e ingresos esperados. Puedes registrar un pago directamente desde su fecha.</p></div>
    <div class="calendar-period-nav"><a href="<?=e(app_url('calendario?period='.$prev))?>" aria-label="Mes anterior">‹</a><strong><?=e($monthLabel)?></strong><a href="<?=e(app_url('calendario?period='.$next))?>" aria-label="Mes siguiente">›</a><a class="calendar-today" href="<?=e(app_url('calendario?period='.date('Y-m')))?>">Hoy</a></div>
  </div>

  <div class="calendar-summary">
    <div><span>Comprometido</span><strong>S/ <?=number_format($commitTotal,2)?></strong></div>
    <div><span>Pagado</span><strong class="amount-in">S/ <?=number_format($paidTotal,2)?></strong></div>
    <div><span>Pendiente</span><strong class="amount-out">S/ <?=number_format($pendingTotal,2)?></strong></div>
    <div><span>Ingresos esperados</span><strong>S/ <?=number_format($expectedIncome,2)?></strong></div>
  </div>

  <div class="calendar-legend"><span><i class="pending"></i>Pendiente</span><span><i class="partial"></i>Parcial</span><span><i class="paid"></i>Pagado</span><span><i class="income"></i>Ingreso esperado</span><span><i class="skipped"></i>Omitido</span></div>

  <section class="financial-calendar">
    <div class="calendar-weekdays"><?php foreach(['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d):?><b><?=e($d)?></b><?php endforeach;?></div>
    <div class="calendar-grid">
      <?php for($i=0;$i<42;$i++): $day=$gridStart->modify('+'.$i.' days');$date=$day->format('Y-m-d');$inMonth=$day->format('Y-m')===$period;$isToday=$date===date('Y-m-d'); ?>
      <div class="calendar-day <?=$inMonth?'':'outside'?> <?=$isToday?'today':''?>">
        <div class="calendar-day-number"><span><?=$day->format('j')?></span><?=$isToday?'<em>HOY</em>':''?></div>
        <div class="calendar-events">
          <?php foreach($events[$date]??[] as $event):$r=$event['row']; ?>
            <?php if($event['type']==='payment'):
              $status=(string)$r['status'];$clickable=in_array($status,['pending','partial'],true);$amount=$clickable?(float)$r['remaining_amount']:(float)$r['amount']; ?>
              <button type="button" class="calendar-event payment <?=e($status)?>" <?=$clickable?'data-calendar-pay':''?>
                data-id="<?=$r['id']?>" data-name="<?=e($r['name'])?>" data-icon="<?=e($r['icon']?:'🧾')?>" data-due="<?=e($r['due_date'])?>" data-amount="<?=e((string)$r['amount'])?>" data-paid="<?=e((string)$r['paid_amount'])?>" data-remaining="<?=e((string)$r['remaining_amount'])?>" <?=$clickable?'':'disabled'?>>
                <span><?=e($r['icon']?:'🧾')?> <?=e($r['name'])?></span><b>S/ <?=number_format($amount,2)?></b>
              </button>
            <?php else:$status=(string)$r['status']; ?>
              <div class="calendar-event income <?=e($status)?>"><span><?=e($r['icon']?:'💰')?> <?=e($r['name'])?></span><b>S/ <?=number_format((float)$r['expected_amount'],2)?></b></div>
            <?php endif;?>
          <?php endforeach;?>
        </div>
      </div>
      <?php endfor;?>
    </div>
  </section>
</div>

<div class="modal" id="calendarPayModal" aria-hidden="true">
 <div class="modal-card calendar-pay-card">
  <button class="modal-x" type="button" data-calendar-pay-close>×</button>
  <span class="eyebrow">REGISTRAR PAGO</span><h2 id="calendarPayTitle">Confirmar pago</h2><p id="calendarPayMeta" class="muted"></p>
  <div class="calendar-pay-summary"><div><span>Monto del compromiso</span><b id="calendarPayReference">S/ 0.00</b></div><div><span>Ya pagado</span><b id="calendarPayPaid">S/ 0.00</b></div><div><span>Falta</span><b id="calendarPayRemaining">S/ 0.00</b></div></div>
  <form id="calendarPayForm">
   <input type="hidden" id="calendarPayId">
   <div class="form-grid two"><label>¿Desde qué cuenta?<select id="calendarPayAccount" required></select><small id="calendarPayAccountHint"></small></label><label>¿Usar un fondo?<select id="calendarPayFund"><option value="">No, dinero libre</option></select><small id="calendarPayFundHint"></small></label></div>
   <label>Monto pagado<div class="money-input"><span>S/</span><input id="calendarPayAmount" type="text" inputmode="decimal" autocomplete="off" required></div><small>Puedes registrar un abono parcial.</small></label>
   <label>Medio de pago<select id="calendarPayMethod"><option>Transferencia</option><option>Yape</option><option>Plin</option><option>Tarjeta</option><option>Efectivo</option></select></label>
   <div class="modal-actions"><button type="button" class="btn ghost" data-calendar-pay-close>Cancelar</button><button type="submit" class="btn primary" id="calendarPaySubmit">✓ Registrar pago</button></div>
  </form>
 </div>
</div>
<script>window.MiDineroCalendarData=<?=json_encode($bootstrap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
<script src="<?=e(app_url('assets/js/calendar.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/calendar.js'))?>"></script>
<?php page_bottom(); ?>
