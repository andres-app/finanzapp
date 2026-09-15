<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth(); FinanceSchema::ensure($uid); require __DIR__.'/../app/layout.php';
$period=date('Y-m');
$dash=FinanceService::dashboard($uid,$period);
$summary=$dash['summary'];
$accounts=$dash['accounts'];$protected=FinanceService::savingsReservedByAccount($uid,null);
foreach($accounts as &$a){$a['savings_reserved']=max(0,(float)($protected[(int)$a['id']]??0));$a['spendable_balance']=max(0,(float)$a['balance']-$a['savings_reserved']);}unset($a);
$bootstrap=['total_cash'=>(float)$summary['available_in_accounts'],'pending'=>(float)$summary['pending_total'],'after_commitments'=>(float)$summary['after_commitments'],'accounts'=>$accounts,'threshold'=>1000];
page_top('Planificador de compras','planificador');
?>
<div class="page-wrap planner-page">
  <div class="page-head"><div><span class="eyebrow">ANTES DE GASTAR</span><h1>Planificador de compras</h1><p>Simula una compra y mira cómo quedaría tu dinero antes de decidir. No modifica ningún saldo.</p></div></div>

  <div class="planner-layout">
    <section class="planner-form-card">
      <span class="planner-step">SIMULACIÓN</span><h2>¿Qué estás pensando comprar?</h2>
      <form id="purchasePlannerForm">
        <label>Compra o gasto<input id="plannerName" type="text" placeholder="Ej. TV, sofá, viaje, celular…" autocomplete="off"></label>
        <label>Monto<div class="money-input planner-money"><span>S/</span><input id="plannerAmount" type="text" inputmode="decimal" placeholder="0.00" autocomplete="off"></div></label>
        <label>¿De qué cuenta saldría?<select id="plannerAccount"><option value="0">Cualquiera · ver saldo total</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>" data-spendable="<?=e((string)$a['spendable_balance'])?>" data-balance="<?=e((string)$a['balance'])?>" data-name="<?=e($a['name'])?>"><?=e(($a['icon']?:'🏦').' '.$a['name'])?> · S/ <?=number_format((float)$a['spendable_balance'],2)?></option><?php endforeach;?></select></label>
        <label class="planner-check"><input id="plannerIncludePending" type="checkbox" checked><span><b>Considerar pagos pendientes</b><small>También calcular cuánto quedaría después de pagar tus compromisos actuales.</small></span></label>
        <button class="btn primary planner-calc" type="submit">Calcular impacto</button>
      </form>
      <div class="planner-safe-note"><span>✓</span><p>Esta herramienta solo simula. No registra gastos ni mueve dinero.</p></div>
    </section>

    <section class="planner-result-card" id="plannerResult" aria-live="polite">
      <div class="planner-result-empty"><span>🛒</span><h2>Prueba una compra</h2><p>Ingresa un monto para saber cuánto dinero te quedaría realmente.</p></div>
      <div class="planner-result-content" hidden>
        <div class="planner-result-head"><div><span>IMPACTO ESTIMADO</span><h2 id="plannerResultName">Tu compra</h2></div><span class="planner-risk" id="plannerRisk">—</span></div>
        <div class="planner-metrics">
          <div><span>Disponible hoy</span><strong id="plannerCurrent">S/ 0.00</strong></div>
          <div><span>Después de comprar</span><strong id="plannerAfterPurchase">S/ 0.00</strong></div>
          <div class="featured"><span>Después de pagar compromisos</span><strong id="plannerAfterAll">S/ 0.00</strong><small id="plannerPendingNote"></small></div>
        </div>
        <div class="planner-account-impact" id="plannerAccountImpact" hidden><span>Cuenta seleccionada</span><b id="plannerAccountText"></b></div>
        <div class="planner-explanation" id="plannerExplanation"></div>
        <div class="planner-actions"><button type="button" class="btn ghost" id="plannerSaveScenario">Guardar simulación</button><a class="btn soft" href="<?=e(app_url('calendario'))?>">Ver próximos pagos</a></div>
      </div>
    </section>
  </div>

  <section class="planner-history-card" id="plannerHistoryCard" hidden><div class="table-card-head"><strong>Simulaciones recientes</strong><button type="button" id="plannerClearHistory">Limpiar</button></div><div id="plannerHistory" class="planner-history"></div></section>
</div>
<script>window.MiDineroPlannerData=<?=json_encode($bootstrap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
<script src="<?=e(app_url('assets/js/purchase-planner.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/purchase-planner.js'))?>"></script>
<?php page_bottom(); ?>
