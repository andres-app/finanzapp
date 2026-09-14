<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth(); FinanceSchema::ensure($uid); require __DIR__.'/../app/layout.php';
$period=$_GET['period']??date('Y-m');$u=current_user();$firstName=trim(explode(' ',trim($u['name']??''))[0]??'');

// Datos precargados para que las acciones rápidas funcionen sin esperar AJAX.
$formCatsSt=db()->prepare('SELECT id,name,type,icon,is_ant_expense FROM categories WHERE user_id=? AND active=1 ORDER BY name');
$formCatsSt->execute([$uid]);
$formConceptsSt=db()->prepare('SELECT id,category_id,name,default_amount,is_ant_expense FROM concepts WHERE user_id=? AND active=1 ORDER BY name');
$formConceptsSt->execute([$uid]);
$formAccounts=FinanceService::accountBalances($uid);$formProtected=FinanceService::savingsReservedByAccount($uid,null);
foreach($formAccounts as &$fa){$fa['savings_reserved']=max(0,(float)($formProtected[(int)$fa['id']]??0));$fa['spendable_balance']=max(0,(float)$fa['balance']-$fa['savings_reserved']);}unset($fa);
$formIncomeDefaults=[];
try {
  $formRiSt=db()->prepare('SELECT r.concept_id,r.account_id,r.name,a.name account_name,a.icon account_icon FROM recurring_incomes r LEFT JOIN financial_accounts a ON a.id=r.account_id AND a.user_id=r.user_id WHERE r.user_id=? AND r.active=1 AND r.concept_id IS NOT NULL AND r.account_id IS NOT NULL ORDER BY r.id');
  $formRiSt->execute([$uid]);
  $formIncomeDefaults=$formRiSt->fetchAll();
} catch (Throwable $e) {
  error_log('[MiDinero dashboard income defaults] '.$e->getMessage());
}
$formData=[
  'categories'=>$formCatsSt->fetchAll(),
  'concepts'=>$formConceptsSt->fetchAll(),
  'accounts'=>$formAccounts,
  'funds'=>array_values(array_filter(FinanceService::funds($uid),fn($f)=>empty($f['savings_goal_id']))),
  'income_defaults'=>$formIncomeDefaults,
];
$months=['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
[$py,$pm]=explode('-',preg_match('/^\d{4}-\d{2}$/',$period)?$period:date('Y-m'));$periodLabel=($months[$pm]??$pm).' '.$py;
page_top('Dashboard','dashboard');
?>
<div class="dashboard-minimal-v13">
  <header class="minimal-dashboard-head">
    <div>
      <span class="eyebrow">PANORAMA FINANCIERO</span>
      <h1>Hola, <?=e($firstName ?: 'Andrés')?></h1>
      <p>Lo esencial de tu dinero, en un vistazo.</p>
    </div>
    <div class="minimal-head-actions">
      <label class="date-chip"><input id="period" type="month" value="<?=e($period)?>"><span id="periodLabel"><?=e($periodLabel)?></span><svg viewBox="0 0 24 24"><path d="M7 3v3m10-3v3M4.5 9h15M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg></label>
      <button class="round-control" id="prevMonth" type="button">‹</button>
      <button class="round-control" id="nextMonth" type="button">›</button>
      <div class="register-launcher">
        <button class="btn primary dash-new" id="newTx" type="button" aria-haspopup="menu" aria-expanded="false"><span class="dash-new-icon">+</span><span>Registrar</span></button>
        <div class="register-menu" id="registerMenu" role="menu" aria-hidden="true">
          <button type="button" role="menuitem" data-quick-action="expense"><span class="register-menu-icon expense">↓</span><span><b>Gasté</b><small>Registrar un gasto</small></span></button>
          <button type="button" role="menuitem" data-quick-action="income"><span class="register-menu-icon income">+</span><span><b>Recibí dinero</b><small>Registrar un ingreso</small></span></button>
          <button type="button" role="menuitem" data-quick-action="transfer"><span class="register-menu-icon transfer">↔</span><span><b>Moví dinero</b><small>Entre tus cuentas</small></span></button>
          <button type="button" role="menuitem" data-quick-action="allocate"><span class="register-menu-icon allocate">◎</span><span><b>Separé dinero</b><small>Reservar en un fondo</small></span></button>
          <a class="register-menu-saving" role="menuitem" href="<?=e(app_url('ahorro?action=deposit'))?>"><span class="register-menu-icon saving">◆</span><span><b>Guardar en Ahorro</b><small>Proteger dinero en tu chanchito</small></span></a>
        </div>
      </div>
    </div>
  </header>

  <section class="minimal-money-card">
    <div class="minimal-money-main">
      <span>SALDO DISPONIBLE EN CUENTAS</span>
      <strong id="freeToSpend">S/ 0.00</strong>
      <p id="cashExplanation">Se descuenta solo cuando registras un pago o gasto.</p>
    </div>
    <div class="minimal-money-stats">
      <div><span>Libre sin asignar</span><strong id="unallocatedHero">S/ 0.00</strong></div>
      <div><span>Por pagar</span><strong id="pendingHero">S/ 0.00</strong></div>
      <div><span>En fondos</span><strong id="reservedTotal">S/ 0.00</strong></div>
      <div><span>En ahorro</span><strong id="heroSavingsTotal">S/ 0.00</strong></div>
    </div>
  </section>

  <section class="minimal-month-section">
    <div class="minimal-section-title"><div><h2>Resumen del mes</h2><p><?=e($periodLabel)?></p></div><span class="live-badge"><i></i> Actualizado</span></div>
    <div class="minimal-month-row">
      <div><span>Ingresó</span><strong id="monthIncome">S/ 0.00</strong></div>
      <div><span>Salió</span><strong id="monthExpense">S/ 0.00</strong></div>
      <div class="net"><span>Balance</span><strong id="monthNet">S/ 0.00</strong></div>
      <div class="ant"><span>Gastos hormiga</span><strong id="antTotal">S/ 0.00</strong></div>
    </div>
    <span id="incomeChange" class="sr-only"></span><span id="expenseChange" class="sr-only"></span><span id="antProjection" class="sr-only"></span><span id="quickUnallocated" class="sr-only"></span>
  </section>

  <section class="minimal-dashboard-grid">
    <article class="minimal-panel minimal-payments-panel">
      <div class="minimal-panel-head">
        <div><span>PENDIENTES</span><h2>Pagos por atender</h2><p>Solo lo que requiere tu atención.</p></div>
        <div class="minimal-panel-total"><strong id="railPendingTotal">S/ 0.00</strong><a href="<?=e(app_url('configuracion/pagos'))?>">Ver todos</a></div>
      </div>
      <div id="railPendingList" class="rail-payment-list minimal-payment-list"></div>
    </article>

    <article class="minimal-panel minimal-savings-panel">
      <div class="minimal-panel-head">
        <div><span>CHANCHITO</span><h2>Tu ahorro</h2><p>Dinero protegido para tus objetivos.</p></div>
        <a class="minimal-link" href="<?=e(app_url('ahorro'))?>">Ver detalle</a>
      </div>
      <div class="minimal-savings-value"><strong id="dashboardSavingsTotal">S/ 0.00</strong><a class="btn primary" id="dashboardSaveBtn" href="<?=e(app_url('ahorro?action=deposit'))?>">＋ Ahorrar</a></div>
      <p class="minimal-saving-goal" id="dashboardSavingsGoal">Configura tu primera meta de ahorro</p>
      <div class="minimal-progress"><i id="dashboardSavingsProgress" style="width:0%"></i></div>
      <div class="minimal-savings-foot"><span id="dashboardSavingsPct">0% de la meta</span><span id="dashboardSavingsAccount">Sin cuenta definida</span></div>
      <span id="heroSavingsMirror" class="sr-only"></span>
    </article>
  </section>

  <section class="minimal-quick-links">
    <a href="<?=e(app_url('fondos'))?>"><span>◎</span><div><b>Fondos</b><small>Organiza dinero para gastos previstos</small></div><strong>→</strong></a>
    <a href="<?=e(app_url('cuentas'))?>"><span>🏦</span><div><b>Cuentas</b><small>Revisa dónde está tu dinero</small></div><strong>→</strong></a>
    <a href="<?=e(app_url('movimientos'))?>"><span>↔</span><div><b>Movimientos</b><small>Consulta el historial completo</small></div><strong>→</strong></a>
  </section>
</div>

<!-- Acciones independientes: cada operación tiene su propio modal compacto -->
<div class="modal quick-modal action-modal" id="expenseModal" aria-hidden="true">
  <div class="modal-card quick-modal-card action-modal-card expense-modal-card">
    <div class="quick-modal-top action-modal-top">
      <div class="action-modal-heading"><span class="action-modal-icon expense">↓</span><div><span>GASTO RÁPIDO</span><h3>¿Cuánto gastaste?</h3><p>Registra una compra o pago en pocos segundos.</p></div></div>
      <button class="modal-x" type="button" data-action-close="expense">×</button>
    </div>
    <form class="quick-screen quick-form" id="expenseForm">
      <div class="quick-amount"><label>Monto</label><div class="money-entry-shell"><span>S/</span><input name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div></div>
      <div class="quick-field concept-required-field"><label>¿En qué gastaste?</label><div class="quick-concept-chips" id="expenseChips"></div><select name="concept_id" id="expenseConcept" required><option value="" selected disabled>Selecciona un concepto</option></select><button type="button" class="quick-new-concept" data-quick-concept-create="expense"><span>＋</span> ¿No está en la lista? Crear concepto</button></div>
      <div class="quick-two action-compact-grid"><div class="quick-field"><label>¿De dónde pagaste?</label><select name="account_id" id="expenseAccount" required></select><small id="expenseAccountHint"></small></div><div class="quick-field"><label>¿Usaste un fondo? <em>Opcional</em></label><select name="fund_id" id="expenseFund"><option value="">No, dinero libre</option></select></div></div>
      <details class="quick-more"><summary>Agregar detalles</summary><div class="quick-more-grid">
        <div><label>Categoría</label><select name="category_id" id="expenseCategory" required></select></div>
        <div><label>Fecha</label><input name="occurred_at" type="datetime-local" required></div>
        <div><label>Medio de pago</label><select name="payment_method"><option>Transferencia</option><option>Yape</option><option>Plin</option><option>Tarjeta</option><option>Efectivo</option></select></div>
        <div><label>Nota</label><input name="description" placeholder="Opcional"></div>
      </div></details>
      <button class="btn primary quick-submit" type="submit">Registrar gasto</button>
    </form>
  </div>
</div>

<div class="modal quick-modal action-modal" id="incomeModal" aria-hidden="true">
  <div class="modal-card quick-modal-card action-modal-card income-modal-card">
    <div class="quick-modal-top action-modal-top">
      <div class="action-modal-heading"><span class="action-modal-icon income">+</span><div><span>INGRESO RÁPIDO</span><h3>¿Cuánto recibiste?</h3><p>Registra tu sueldo o cualquier ingreso.</p></div></div>
      <button class="modal-x" type="button" data-action-close="income">×</button>
    </div>
    <form class="quick-screen quick-form" id="incomeForm">
      <div class="quick-amount income"><label>Monto</label><div class="money-entry-shell"><span>S/</span><input name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div></div>
      <div class="quick-field concept-required-field"><label>¿Qué dinero recibiste?</label><div class="quick-concept-chips" id="incomeChips"></div><select name="concept_id" id="incomeConcept" required><option value="" selected disabled>Selecciona un concepto</option></select><button type="button" class="quick-new-concept" data-quick-concept-create="income"><span>＋</span> ¿No está en la lista? Crear concepto</button></div>
      <div class="quick-field income-destination-field"><label>Cuenta que recibió el dinero</label><div class="income-account-auto" id="incomeAccountAuto" hidden></div><select name="account_id" id="incomeAccount" required><option value="" selected disabled>Elige una cuenta</option></select><small id="incomeAccountHint">Elige dónde ingresó realmente este dinero.</small></div>
      <details class="quick-more"><summary>Agregar detalles</summary><div class="quick-more-grid">
        <div><label>Categoría</label><select name="category_id" id="incomeCategory" required></select></div>
        <div><label>Fecha</label><input name="occurred_at" type="datetime-local" required></div>
        <div class="full"><label>Nota</label><input name="description" placeholder="Opcional"></div>
      </div></details>
      <input type="hidden" name="payment_method" value="Transferencia">
      <button class="btn primary quick-submit income" type="submit">Registrar ingreso</button>
    </form>
  </div>
</div>

<div class="modal quick-modal action-modal" id="transferModal" aria-hidden="true">
  <div class="modal-card quick-modal-card action-modal-card transfer-modal-card">
    <div class="quick-modal-top action-modal-top">
      <div class="action-modal-heading"><span class="action-modal-icon transfer">↔</span><div><span>MOVER DINERO</span><h3>Entre tus cuentas</h3><p>No cambia tus ingresos ni tus gastos.</p></div></div>
      <button class="modal-x" type="button" data-action-close="transfer">×</button>
    </div>
    <form class="quick-screen quick-form" id="transferQuickForm">
      <div class="quick-amount"><label>¿Cuánto vas a mover?</label><div class="money-entry-shell"><span>S/</span><input name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div></div>
      <div class="quick-two transfer-account-grid"><div class="quick-field account-select-field"><label>Sale de</label><select name="from_account_id" id="transferFrom" required></select><small id="transferFromHint" class="account-select-hint"></small></div><div class="quick-field account-select-field"><label>Llega a</label><select name="to_account_id" id="transferTo" required></select><small id="transferToHint" class="account-select-hint"></small></div></div>
      <details class="quick-more"><summary>Agregar nota o cambiar fecha</summary><div class="quick-more-grid"><div><label>Fecha</label><input name="occurred_at" type="datetime-local" required></div><div><label>Nota</label><input name="description" placeholder="Ej. BCP a Yape"></div></div></details>
      <button class="btn primary quick-submit" type="submit">Mover dinero</button>
    </form>
  </div>
</div>

<div class="modal quick-modal action-modal" id="allocateModal" aria-hidden="true">
  <div class="modal-card quick-modal-card action-modal-card allocate-modal-card">
    <div class="quick-modal-top action-modal-top">
      <div class="action-modal-heading"><span class="action-modal-icon allocate">◎</span><div><span>SEPARAR DINERO</span><h3>Reserva para un fondo</h3><p>No es un gasto: tu dinero sigue siendo tuyo.</p></div></div>
      <button class="modal-x" type="button" data-action-close="allocate">×</button>
    </div>
    <form class="quick-screen quick-form" id="allocateQuickForm">
      <div class="quick-available"><span>Disponible sin asignar</span><strong id="quickUnallocated">S/ 0.00</strong></div>
      <div class="quick-amount"><label>¿Cuánto quieres separar?</label><div class="money-entry-shell"><span>S/</span><input name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div></div>
      <div class="quick-field"><label>¿Para qué fondo?</label><div class="fund-choice-grid" id="fundChoiceGrid"></div><select name="fund_id" id="allocateFund" required></select></div>
      <button class="btn primary quick-submit" type="submit">Separar dinero</button>
    </form>
  </div>
</div>

<div class="modal quick-concept-modal" id="quickConceptModal" aria-hidden="true">
  <div class="modal-card quick-concept-modal-card">
    <div class="quick-concept-modal-head">
      <div><span id="quickConceptKicker">NUEVO CONCEPTO</span><h3 id="quickConceptTitle">Crear concepto</h3><p id="quickConceptHelp">Créalo aquí y úsalo inmediatamente en este movimiento.</p></div>
      <button class="modal-x" type="button" data-quick-concept-close>×</button>
    </div>
    <form id="quickConceptForm" class="quick-concept-create-form">
      <input type="hidden" name="type" id="quickConceptType" value="expense">
      <div class="quick-concept-grid">
        <div class="full"><label>Nombre del concepto</label><input name="name" id="quickConceptName" placeholder="Ej. Farmacia, taxi, bono..." autocomplete="off" required></div>
        <div><label>Categoría</label><select name="category_id" id="quickConceptCategory" required></select></div>
        <div><label>Monto sugerido <small>Opcional</small></label><div class="quick-concept-money money-entry-shell"><span>S/</span><input name="default_amount" id="quickConceptAmount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input></div></div>
      </div>
      <label class="quick-concept-ant" id="quickConceptAntWrap"><input type="checkbox" name="is_ant_expense" id="quickConceptAnt"><span><b>Marcar como gasto hormiga</b><small>Úsalo para delivery, snacks o pequeños gastos repetitivos.</small></span></label>
      <div class="quick-concept-actions"><button type="button" class="btn" data-quick-concept-close>Cancelar</button><button type="submit" class="btn primary" id="quickConceptSubmit">Crear y usar</button></div>
    </form>
  </div>
</div>

<div class="quick-toast" id="quickToast" role="status" aria-live="polite"></div>

<div class="modal" id="payModal" aria-hidden="true"><div class="modal-card payment-modal-card"><div class="modal-head payment-modal-head"><div><span>REGISTRAR PAGO</span><h3>Confirmar pago</h3><p>Indica de dónde salió el dinero. El monto puede ser distinto al referencial.</p></div><button class="modal-x" type="button" data-pay-close>×</button></div><form id="payForm"><input type="hidden" id="payId">
  <div class="payment-concept-card"><span class="payment-concept-icon" id="payIcon">⌂</span><div class="payment-concept-info"><small>Concepto</small><strong id="payName">Pago mensual</strong><span id="payDue">Vencimiento</span></div><div class="payment-reference"><small>Monto referencial</small><strong id="payReference">S/ 0.00</strong></div></div>
  <div class="form-grid pay-source-grid"><div class="account-select-field"><label>¿Desde qué cuenta?</label><select id="payAccount" required></select><small id="payAccountHint" class="account-select-hint"></small></div><div><label>¿Usar un fondo?</label><select id="payFund"><option value="">No, dinero libre</option></select></div></div>
  <div class="payment-amount-block"><label for="payAmount">Monto pagado</label><div class="payment-money-input money-entry-shell"><span>S/</span><input id="payAmount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" data-money-input required></div><p>Este monto se registrará como gasto.</p></div>
  <div class="payment-method-block"><label for="payMethod">Medio de pago</label><select id="payMethod"><option>Transferencia</option><option>Yape</option><option>Plin</option><option>Tarjeta</option><option>Efectivo</option></select></div>
  <div class="payment-modal-actions"><button class="btn payment-cancel" type="button" data-pay-close>Cancelar</button><button class="btn primary payment-confirm" id="paySubmit" type="submit">✓ Registrar pago</button></div>
</form></div></div>
<script>window.FINANCE_FORM_DATA = <?=json_encode($formData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="<?=e(app_url('assets/js/dashboard.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/dashboard.js'))?>"></script>
<?php page_bottom(); ?>
