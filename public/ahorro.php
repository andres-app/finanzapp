<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
FinanceSchema::ensure($uid);
require __DIR__.'/../app/layout.php';
$moduleError=null;$historyError=null;
$savings=['goals'=>[],'total_saved'=>0,'assigned_to_goals'=>0,'general_saved'=>0,'unassigned_saved'=>0,'account_breakdown'=>[],'general_account_breakdown'=>[],'total_target'=>0,'remaining'=>0,'progress'=>0];
$accounts=[];$history=[];$free=0;
try {
    SavingsSchema::ensure($uid);
    $savings=FinanceService::savingsOverview($uid);
    $accounts=FinanceService::accountBalances($uid);
} catch (Throwable $e) {
    error_log('[MiDinero ahorro page v9 overview] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    $moduleError='No se pudo cargar el resumen de ahorro.';
}
if(!$moduleError){
    try{$history=FinanceService::savingsHistory($uid,40);}catch(Throwable $e){$historyError='El historial no pudo cargarse temporalmente.';error_log('[MiDinero ahorro page v9 history] '.$e->getMessage());}
    try{
        // Usa exactamente la misma disponibilidad segura que el Dashboard:
        // efectivo - fondos/ahorro - compromisos pendientes no cubiertos.
        $dashFree=FinanceService::dashboard($uid,date('Y-m'));
        $free=max(0,(float)($dashFree['summary']['free_to_spend']??0));
    } catch(Throwable $e){
        try{$free=max(0,FinanceService::freeToSpendNow($uid));}
        catch(Throwable $e2){try{$free=max(0,FinanceService::totalCash($uid)-FinanceService::totalReserved($uid));}catch(Throwable $ignored){$free=0;}}
        error_log('[MiDinero ahorro page v17 free] '.$e->getMessage());
    }
}
page_top('Ahorro','ahorro');
?>
<div class="page-wrap savings-page savings-v9">
  <div class="page-head savings-page-head">
    <div><span class="eyebrow">TU CHANCHITO DIGITAL</span><h1>Ahorro</h1><p>Protege dinero para que no se mezcle con lo que puedes gastar.</p></div>
    <?php if(!$moduleError):?><button type="button" class="btn primary savings-main-action" id="openSavingsDeposit">＋ Guardar en ahorro</button><?php endif;?>
  </div>

  <?php if($moduleError):?>
    <section class="savings-error-card"><b>No se pudo cargar el ahorro.</b><p><?=e($moduleError)?> Abre <code>/api/savings_health.php</code> para revisar el diagnóstico.</p></section>
  <?php else:?>
  <section class="savings-summary-v6 savings-summary-v9">
    <article class="primary"><span>AHORRO PROTEGIDO</span><strong>S/ <?=number_format((float)$savings['total_saved'],2)?></strong><small>No se puede usar en gastos hasta que lo retires.</small></article>
    <article><span>Con meta</span><strong>S/ <?=number_format((float)$savings['assigned_to_goals'],2)?></strong><small>Asignado a tus objetivos.</small></article>
    <article><span>Sin meta</span><strong>S/ <?=number_format((float)$savings['general_saved'],2)?></strong><small>Ahorro general protegido.</small></article>
    <article><span>Disponible para ahorrar</span><strong>S/ <?=number_format(max(0,$free),2)?></strong><small>Sin tocar compromisos.</small></article>
  </section>

  <section class="savings-protection-note">
    <span>🔒</span><div><b>Funciona como un chanchito.</b><small>El dinero sigue en tu cuenta bancaria, pero Mi Dinero lo bloquea para gastos y transferencias normales. Para volver a usarlo debes retirarlo desde Ahorro.</small></div>
  </section>

  <section class="finance-panel savings-vault-card">
    <div class="section-heading"><div><span class="eyebrow">FONDO AHORRO</span><h2>¿Dónde está guardado?</h2><p>Una sola bolsa de ahorro, distribuida entre tus cuentas reales.</p></div><button type="button" class="btn" id="openSavingsDepositInline">＋ Guardar</button></div>
    <div class="savings-vault-accounts">
      <?php if(empty($savings['account_breakdown'])):?><div class="empty compact">Todavía no has protegido dinero.</div><?php endif;?>
      <?php foreach(($savings['account_breakdown']??[]) as $ab):?>
        <div class="savings-vault-account"><span><?=e($ab['icon']?:'🏦')?></span><div><b><?=e($ab['name'])?></b><small>Protegido dentro de esta cuenta</small></div><strong>S/ <?=number_format((float)$ab['amount'],2)?></strong></div>
      <?php endforeach;?>
    </div>
  </section>

  <?php if((float)$savings['general_saved']>0.005):?>
  <section class="savings-general-card">
    <div><span class="savings-goal-icon">🐷</span><div><small>AHORRO GENERAL</small><h3>Sin una meta específica</h3><p>Dinero protegido que todavía no asignaste a un objetivo.</p></div></div>
    <strong>S/ <?=number_format((float)$savings['general_saved'],2)?></strong>
    <div class="savings-goal-actions"><button type="button" class="btn primary" data-save-to-goal="0">＋ Guardar más</button><button type="button" class="btn" data-withdraw-general="0">Retirar</button></div>
  </section>
  <?php endif;?>

  <section class="savings-section-head"><div><h2>Metas de ahorro</h2><p>Las metas son opcionales: sirven para decir para qué estás ahorrando.</p></div><a href="<?=e(app_url('configuracion/metas'))?>" class="text-link">Administrar metas</a></section>
  <div class="savings-goal-grid savings-goal-grid-v6">
    <?php if(!$savings['goals']): ?>
      <div class="savings-empty"><span>◎</span><h3>Aún no tienes metas</h3><p>Puedes ahorrar sin meta o crear una para Emergencia, Viaje, Casa, etc.</p><a class="btn" href="<?=e(app_url('configuracion/metas'))?>">Crear meta</a></div>
    <?php endif; ?>
    <?php foreach($savings['goals'] as $g): $pct=(float)$g['progress']; ?>
      <article class="savings-goal-card savings-goal-card-v6" data-goal-card="<?=e($g['id'])?>">
        <div class="savings-goal-top"><span class="savings-goal-icon">🎯</span><div><small>META · <?=e($g['period'])?></small><h3><?=e($g['name'])?></h3></div><button type="button" class="savings-edit-btn" data-edit-goal="<?=e($g['id'])?>" data-target="<?=e($g['target_amount'])?>" data-period="<?=e($g['period'])?>">⋯</button></div>
        <div class="savings-goal-amount"><span>Protegido para esta meta</span><strong>S/ <?=number_format((float)$g['saved_amount'],2)?></strong><small>de S/ <?=number_format((float)$g['target_amount'],2)?></small></div>
        <div class="savings-progress"><i style="width:<?=min(100,max(0,$pct))?>%;background:<?=e($g['fund_color'])?>"></i></div>
        <div class="savings-goal-meta"><span><b><?=number_format($pct,0)?>%</b> completado</span><span>Falta <b>S/ <?=number_format((float)$g['remaining_amount'],2)?></b></span></div>
        <div class="savings-where-v6"><small>Dónde está guardado</small>
          <?php if(empty($g['account_breakdown'])):?><span>Sin aportes todavía</span><?php else:?>
            <?php foreach(array_slice($g['account_breakdown'],0,3) as $ab):?><div><span><?=e($ab['icon']?:'🏦')?> <?=e($ab['name'])?></span><b>S/ <?=number_format((float)$ab['amount'],2)?></b></div><?php endforeach;?>
          <?php endif;?>
        </div>
        <div class="savings-goal-actions"><button type="button" class="btn primary" data-save-to-goal="<?=e($g['id'])?>">＋ Guardar</button><?php $hasTraced=false; foreach(($g['account_breakdown']??[]) as $ab){ if(!empty($ab['id']) && (float)$ab['amount']>0.005){$hasTraced=true;break;} } ?>
        <?php if((float)$g['saved_amount']>0 && $hasTraced):?><button type="button" class="btn" data-withdraw-goal="<?=e($g['id'])?>">Retirar</button><?php endif;?></div>
      </article>
    <?php endforeach; ?>
  </div>

  <section class="finance-panel savings-history-panel"><div class="section-heading"><div><h2>Historial</h2><p>Cada vez que guardas o retiras dinero queda registrado.</p></div></div>
    <div class="savings-history-list"><?php if($historyError):?><div class="empty compact"><?=e($historyError)?></div><?php endif;?>
      <?php if(!$history):?><div class="empty compact">Aún no hay movimientos de ahorro.</div><?php endif;?>
      <?php foreach($history as $h):?><div class="savings-history-row"><span class="savings-history-icon <?=$h['movement_type']==='deposit'?'in':'out'?>"><?=$h['movement_type']==='deposit'?'🔒':'🔓'?></span><div><b><?=$h['movement_type']==='deposit'?'Guardaste para ':'Retiraste de '?><?=e($h['goal_name'])?></b><small><?=e(date('d/m/Y H:i',strtotime($h['occurred_at'])))?> · <?=e(($h['from_account']?:'—').' → '.($h['to_account']?:'—'))?><?=!empty($h['note'])?' · '.e($h['note']):''?></small></div><strong class="<?=$h['movement_type']==='deposit'?'amount-in':'amount-out'?>"><?=$h['movement_type']==='deposit'?'+':'−'?> S/ <?=number_format((float)$h['amount'],2)?></strong></div><?php endforeach; ?>
    </div>
  </section>
  <?php endif;?>
</div>

<?php if(!$moduleError):?>
<div class="modal savings-modal" id="savingsDepositModal" aria-hidden="true"><div class="modal-card savings-modal-card savings-deposit-v6 savings-deposit-v9"><div class="modal-head"><div><span>FONDO AHORRO</span><h3>Guardar dinero</h3><p>Lo que guardes quedará protegido y no podrás gastarlo hasta retirarlo.</p></div><button class="modal-x" type="button" data-savings-close>×</button></div><form id="savingsDepositForm">
  <label>Monto a proteger</label><div class="payment-money-input savings-money-v6 money-entry-shell"><span>S/</span><input name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div>
  <label>¿De qué cuenta sale?</label><select name="from_account_id" id="savingFrom" required></select><small id="savingFromHint" class="saving-hint"></small>
  <label>Meta <em class="optional-label">Opcional</em></label><select name="goal_id" id="savingGoal"></select>
  <label>¿Dónde quedará físicamente?</label>
  <div class="savings-custody-choice" id="savingCustodyChoice">
    <label><input type="radio" name="custody_mode" value="same" checked><span><b>En la misma cuenta</b><small>Solo se bloquea dentro del sistema.</small></span></label>
    <label><input type="radio" name="custody_mode" value="other"><span><b>En otra cuenta</b><small>También hacemos la transferencia.</small></span></label>
  </div>
  <div id="savingDestinationWrap" hidden><label>Cuenta donde guardarás el ahorro</label><select name="to_account_id" id="savingTo"></select><small id="savingToHint" class="saving-hint"></small></div>
  <div class="savings-result-preview savings-result-preview-v9" id="savingsPreview"></div>
  <details class="quick-more"><summary>Nota o cambiar fecha</summary><div class="quick-more-grid"><div><label>Fecha</label><input name="occurred_at" type="datetime-local" required></div><div><label>Nota</label><input name="note" placeholder="Opcional"></div></div></details>
  <button class="btn primary block" type="submit">🔒 Guardar en Ahorro</button>
</form></div></div>

<div class="modal savings-modal" id="savingsWithdrawModal" aria-hidden="true"><div class="modal-card savings-modal-card"><div class="modal-head"><div><span>ABRIR EL CHANCHITO</span><h3>Retirar del ahorro</h3><p>Este dinero volverá a estar disponible para gastar.</p></div><button class="modal-x" type="button" data-savings-withdraw-close>×</button></div><form id="savingsWithdrawForm">
  <input type="hidden" name="goal_id" id="withdrawGoal"><label>Monto a liberar</label><div class="payment-money-input money-entry-shell"><span>S/</span><input name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div>
  <label>Cuenta donde está protegido</label><select name="from_account_id" id="withdrawFrom" required></select><label>¿Dónde quedará disponible?</label><select name="to_account_id" id="withdrawTo" required></select>
  <details class="quick-more"><summary>Nota o fecha</summary><div class="quick-more-grid"><div><label>Fecha</label><input name="occurred_at" type="datetime-local" required></div><div><label>Nota</label><input name="note" placeholder="Opcional"></div></div></details><button class="btn primary block" type="submit">🔓 Retirar del Ahorro</button>
</form></div></div>

<div class="modal savings-modal" id="savingsEditModal" aria-hidden="true"><div class="modal-card savings-modal-card small"><div class="modal-head"><div><span>CONFIGURAR META</span><h3>Meta de ahorro</h3><p>La meta solo define para qué estás ahorrando.</p></div><button class="modal-x" type="button" data-savings-edit-close>×</button></div><form id="savingsEditForm"><input type="hidden" name="goal_id" id="editSavingGoal"><label>Monto objetivo</label><div class="payment-money-input money-entry-shell"><span>S/</span><input name="target_amount" id="editSavingTarget" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div><label>Mes objetivo</label><input type="month" name="period" id="editSavingPeriod" required><button class="btn primary block">Guardar meta</button></form></div></div>
<script>window.SAVINGS_DATA=<?=json_encode(['savings'=>$savings,'accounts'=>$accounts,'free'=>$free],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
<script src="<?=e(app_url('assets/js/savings.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/savings.js'))?>"></script>
<?php endif;?>
<?php page_bottom(); ?>
