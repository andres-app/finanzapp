<?php
require __DIR__.'/../app/bootstrap.php';$uid=require_auth();FinanceSchema::ensure($uid);require __DIR__.'/../app/layout.php';
$allFunds=FinanceService::funds($uid);$funds=array_values(array_filter($allFunds,fn($f)=>empty($f['savings_goal_id'])));$cash=FinanceService::totalCash($uid);$reserved=FinanceService::totalReserved($uid);$reservedGeneral=array_sum(array_map(fn($f)=>max(0,(float)$f['available']),$funds));$savedReserved=max(0,$reserved-$reservedGeneral);try{$dashFree=FinanceService::dashboard($uid,date('Y-m'));$free=max(0,(float)($dashFree['summary']['free_to_spend']??0));}catch(Throwable $e){try{$free=max(0,FinanceService::freeToSpendNow($uid));}catch(Throwable $e2){$free=max(0,$cash-$reserved);}}
$inc=db()->prepare("SELECT t.id,t.amount,t.occurred_at,COALESCE(co.name,c.name) name,t.amount-COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=t.user_id AND fa.source_transaction_id=t.id AND fa.amount>0),0) remaining FROM transactions t JOIN categories c ON c.id=t.category_id LEFT JOIN concepts co ON co.id=t.concept_id WHERE t.user_id=? AND t.type='income' HAVING remaining>0 ORDER BY t.occurred_at DESC LIMIT 12");$inc->execute([$uid]);$incomes=$inc->fetchAll();
page_top('Fondos','fondos');
?>
<div class="page-wrap finance-page">
  <div class="page-head finance-page-head intuitive-page-head">
    <div><span class="eyebrow">DINERO CON PROPÓSITO</span><h1>Mis fondos</h1><p>Usa estos sobres para gastos previstos: Emergencia, Casa, Delivery o Salidas. Las metas de ahorro se administran en el módulo Ahorro.</p></div>
    <div class="page-head-actions"><a class="btn primary" href="<?=e(app_url('dashboard?action=allocate'))?>">◎ Separar dinero</a></div>
  </div>

  <div class="fund-summary-strip"><div><span>Fondos de gasto</span><strong>S/ <?=number_format($reservedGeneral,2)?></strong></div><div><span>Ahorro aparte</span><strong>S/ <?=number_format($savedReserved,2)?></strong></div><div class="free"><span>Disponible sin asignar</span><strong>S/ <?=number_format($free,2)?></strong></div></div>
  <div class="simple-tip"><span>💡</span><div><b>Separar no significa gastar</b><p>Cuando mandas S/ 500 a Emergencia, tu patrimonio no cambia. Solo estás diciendo “este dinero tiene un propósito”.</p></div></div>

  <div class="funds-grid-large">
    <?php foreach($funds as $f): $avail=(float)$f['available'];$target=(float)$f['target_amount'];$pct=$target>0?min(100,max(0,$avail/$target*100)):0; ?>
    <article class="fund-card-large fund-card-clean" data-fund-card="<?=$f['id']?>">
      <div class="fund-card-head">
        <div class="fund-card-identity">
          <span class="fund-card-icon" data-fund-card-icon><?=e($f['icon'])?></span>
          <div><strong data-fund-card-name><?=e($f['name'])?></strong><small>Fondo disponible</small></div>
        </div>
        <div class="fund-menu-wrap">
          <button type="button" class="fund-more-btn" data-fund-menu-toggle aria-label="Opciones de <?=e($f['name'])?>" aria-expanded="false">⋯</button>
          <div class="fund-card-menu" role="menu">
            <button type="button" data-edit-fund
              data-fund-id="<?=$f['id']?>"
              data-fund-name="<?=e($f['name'])?>"
              data-fund-icon="<?=e($f['icon'])?>"
              data-fund-color="<?=e($f['color'])?>"
              data-fund-target="<?=e(number_format($target,2,'.',''))?>"
              data-fund-available="<?=$avail?>">
              <span>✎</span><div><b>Editar fondo</b><small>Nombre, meta y color</small></div>
            </button>
            <button type="button" class="release-fund fund-menu-danger" data-fund-id="<?=$f['id']?>" data-fund-name="<?=e($f['name'])?>" data-available="<?=$avail?>">
              <span>↩</span><div><b>Liberar dinero</b><small>Volverlo a dinero libre</small></div>
            </button>
          </div>
        </div>
      </div>
      <div class="fund-available"><span>Puedes usar de este fondo</span><strong>S/ <?=number_format($avail,2)?></strong></div>
      <div class="fund-progress-big"><i data-fund-card-progress style="width:<?=$pct?>%;background:<?=e($f['color'])?>"></i></div>
      <div class="fund-goal-line" data-fund-card-goal data-available="<?=$avail?>">
        <?php if($target>0): ?><span>Meta S/ <?=number_format($target,2)?></span><b><?=number_format($pct,0)?>%</b><?php else: ?><span>Sin meta definida</span><b>Opcional</b><?php endif; ?>
      </div>
      <div class="fund-numbers"><span>Separado <b>S/ <?=number_format((float)$f['allocated_net'],2)?></b></span><span>Ya usado <b>S/ <?=number_format((float)$f['spent'],2)?></b></span></div>
    </article>
    <?php endforeach; ?>
  </div>

  <section class="simple-action-banner">
    <span class="simple-action-banner-icon">◎</span><div><h2>¿Quieres separar dinero para algo?</h2><p>Elige un monto y un fondo. Nada más. No se registrará como gasto.</p></div><a class="btn primary" href="<?=e(app_url('dashboard?action=allocate'))?>">Separar dinero</a>
  </section>

  <details class="simple-manage-details">
    <summary>Opciones de fondos</summary>
    <div class="finance-page-grid simple-advanced-grid">
      <section class="finance-panel new-fund-panel"><div class="section-heading"><div><h2>Crear otro fondo</h2><p>Ej. Viajes, Navidad, Salud o Auto.</p></div></div><form id="newFundForm" class="settings-form inline-create-finance"><input name="icon" value="💰"><input name="name" required placeholder="Nombre del fondo"><div class="settings-money-input"><span>S/</span><input name="target_amount" type="number" min="0" step="0.01" placeholder="Meta"></div><input type="color" name="color" value="#6b7280"><button class="btn primary">Crear fondo</button></form></section>
      <section class="finance-panel"><div class="section-heading"><div><h2>Repartir en varios fondos</h2><p>Opción avanzada para distribuir varios montos de una sola vez.</p></div><button class="btn" id="openDistribution" type="button">Repartir varios</button></div></section>
    </div>
  </details>
</div>

<div class="modal" id="distributionModal" aria-hidden="true"><div class="modal-card distribution-card"><div class="modal-head"><div><span>DISTRIBUCIÓN AVANZADA</span><h3>Repartir entre varios fondos</h3><p>Úsalo solo si quieres dividir un ingreso en varios fondos al mismo tiempo.</p></div><button class="modal-x" type="button" data-dist-close>×</button></div><form id="distributionForm"><div class="distribution-available"><span>Disponible sin asignar</span><strong>S/ <?=number_format($free,2)?></strong></div><div class="distribution-source"><label>Origen del dinero (opcional)</label><select name="source_transaction_id"><option value="">Saldo general</option><?php foreach($incomes as $r):?><option value="<?=$r['id']?>"><?=e(date('d/m',strtotime($r['occurred_at'])).' · '.$r['name'].' · Disponible S/ '.number_format((float)$r['remaining'],2))?></option><?php endforeach;?></select></div><div class="distribution-list"><?php foreach($funds as $f):?><label><span class="dist-icon"><?=e($f['icon'])?></span><div><b><?=e($f['name'])?></b><small>Actual S/ <?=number_format((float)$f['available'],2)?></small></div><div class="settings-money-input"><span>S/</span><input type="text" inputmode="decimal" autocomplete="off" data-money-input name="allocation_<?=$f['id']?>" placeholder="0.00"></div></label><?php endforeach;?></div><div class="distribution-footer"><span id="distributionTotal">Total a distribuir: S/ 0.00</span><button class="btn primary">Confirmar distribución</button></div></form></div></div>

<div class="modal" id="fundEditModal" aria-hidden="true"><div class="modal-card small-finance-modal fund-edit-modal"><div class="modal-head"><div><span>OPCIONES DEL FONDO</span><h3 id="fundEditTitle">Editar fondo</h3><p>Los cambios se guardan automáticamente.</p></div><button class="modal-x" type="button" data-fund-edit-close>×</button></div><form id="fundEditForm" autocomplete="off"><input type="hidden" name="id" id="fundEditId"><div class="fund-edit-identity"><label>Icono<input name="icon" id="fundEditIcon" maxlength="8"></label><label>Nombre<input name="name" id="fundEditName" required></label></div><label>Meta del fondo <small>Opcional. Sirve para medir tu avance.</small></label><div class="payment-money-input"><span>S/</span><input name="target_amount" id="fundEditTarget" type="text" inputmode="decimal" autocomplete="off" data-money-input></div><div class="fund-edit-color-row"><div><b>Color del fondo</b><small>Solo cambia la barra de progreso.</small></div><input type="color" name="color" id="fundEditColor"></div><div class="fund-edit-modal-footer"><span class="autosave-status saved" id="fundEditStatus">● Guardado</span><button type="button" class="btn primary" data-fund-edit-close>Listo</button></div></form></div></div>

<div class="modal" id="releaseModal" aria-hidden="true"><div class="modal-card small-finance-modal"><div class="modal-head"><div><span>LIBERAR FONDO</span><h3 id="releaseTitle">Liberar dinero</h3><p>El dinero volverá a quedar disponible sin asignar. No es un ingreso.</p></div><button class="modal-x" type="button" data-release-close>×</button></div><form id="releaseForm"><input type="hidden" name="fund_id" id="releaseFundId"><label>Monto a liberar</label><div class="payment-money-input"><span>S/</span><input id="releaseAmount" type="text" inputmode="decimal" autocomplete="off" data-money-input required></div><button class="btn primary block">Liberar dinero</button></form></div></div>
<script src="<?=e(app_url('assets/js/finance-tools.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/finance-tools.js'))?>"></script>
<?php page_bottom(); ?>
