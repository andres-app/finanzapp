<?php
require __DIR__.'/../app/bootstrap.php';
$uid = require_auth();
FinanceSchema::ensure($uid);
require __DIR__.'/../app/layout.php';

$accounts = FinanceService::accountBalances($uid);
$protectedMap = FinanceService::savingsReservedByAccount($uid, null);
foreach ($accounts as &$a) {
    $a['savings_reserved'] = max(0, (float)($protectedMap[(int)$a['id']] ?? 0));
    $a['spendable'] = max(0, (float)$a['balance'] - $a['savings_reserved']);
}
unset($a);

$total = array_sum(array_map(function ($a) { return (float)$a['balance']; }, $accounts));
$totalProtected = array_sum(array_map(function ($a) { return (float)$a['savings_reserved']; }, $accounts));
$totalSpendable = array_sum(array_map(function ($a) { return (float)$a['spendable']; }, $accounts));

page_top('Cuentas', 'cuentas');
?>
<div class="page-wrap finance-page accounts-clean-page">
  <div class="page-head finance-page-head accounts-clean-head">
    <div>
      <span class="eyebrow">DÓNDE ESTÁ TU DINERO</span>
      <h1>Mis cuentas</h1>
      <p>Una vista simple para revisar saldos, ahorro protegido y dinero realmente disponible.</p>
    </div>
    <div class="page-head-actions accounts-head-actions">
      <a class="btn primary" href="<?=e(app_url('dashboard?action=transfer'))?>">↔ Mover dinero</a>
      <button class="btn secondary" type="button" data-toggle-add-account>＋ Nueva cuenta</button>
    </div>
  </div>

  <section class="accounts-overview-grid">
    <article class="overview-mini-card is-dark">
      <span>Total en tus cuentas</span>
      <strong>S/ <?=number_format($total,2)?></strong>
      <small>Lo que hoy figura en tus bancos, billeteras y efectivo.</small>
    </article>
    <article class="overview-mini-card">
      <span>Protegido en ahorro</span>
      <strong>S/ <?=number_format($totalProtected,2)?></strong>
      <small>Dinero bloqueado tipo chanchito.</small>
    </article>
    <article class="overview-mini-card is-soft">
      <span>Disponible real</span>
      <strong>S/ <?=number_format($totalSpendable,2)?></strong>
      <small>Saldo utilizable antes de considerar pagos pendientes.</small>
    </article>
  </section>

  <div class="simple-tip compact-tip"><span>💡</span><div><b>Regla rápida</b><p>Si el dinero pasa entre tus propias cuentas, usa <strong>Mover dinero</strong>. Si solo quieres protegerlo, usa <strong>Guardar en Ahorro</strong>.</p></div></div>

  <div class="accounts-clean-grid">
    <?php foreach($accounts as $a): ?>
    <form class="account-clean-card autosave-finance" data-endpoint="account_save.php">
      <input type="hidden" name="id" value="<?=$a['id']?>">

      <div class="account-clean-top">
        <div class="account-clean-identity">
          <div class="account-clean-icon"><?=e($a['icon'])?></div>
          <div>
            <h3><?=e($a['name'])?></h3>
            <p><?=e($a['account_type']==='bank' ? 'Cuenta bancaria' : ($a['account_type']==='wallet' ? 'Billetera digital' : ($a['account_type']==='cash' ? 'Efectivo' : 'Otra cuenta')))?></p>
          </div>
        </div>
        <div class="account-clean-top-right">
          <span class="autosave-status saved" data-status>● Guardado</span>
          <details class="account-clean-menu">
            <summary>⋯</summary>
            <div class="account-clean-menu-box">
              <button type="button" class="account-menu-action" data-open-account-edit>Editar cuenta</button>
              <a class="account-menu-action" href="<?=e(app_url('ahorro?action=deposit&from='.$a['id']))?>">Guardar en Ahorro</a>
            </div>
          </details>
        </div>
      </div>

      <div class="account-main-balance">
        <span>Saldo en la cuenta</span>
        <strong>S/ <?=number_format((float)$a['balance'],2)?></strong>
      </div>

      <div class="account-metrics-row">
        <div class="metric-pill warn">
          <span>🔒 En ahorro</span>
          <strong>S/ <?=number_format((float)$a['savings_reserved'],2)?></strong>
        </div>
        <div class="metric-pill ok">
          <span>Disponible real</span>
          <strong>S/ <?=number_format((float)$a['spendable'],2)?></strong>
        </div>
      </div>

      <div class="account-actions-row">
        <a class="btn ghost" href="<?=e(app_url('ahorro?action=deposit&from='.$a['id']))?>">🐷 Guardar en Ahorro</a>
        <a class="btn secondary" href="<?=e(app_url('dashboard?action=transfer&from='.$a['id']))?>">↔ Mover</a>
      </div>

      <details class="account-edit-box" data-account-edit-box>
        <summary>Editar detalles</summary>
        <div class="account-edit-grid">
          <div>
            <label>Nombre</label>
            <input class="title-edit clean-input" name="name" value="<?=e($a['name'])?>">
          </div>
          <div>
            <label>Tipo</label>
            <select class="clean-input" name="account_type">
              <option value="bank" <?=$a['account_type']==='bank'?'selected':''?>>Cuenta bancaria</option>
              <option value="wallet" <?=$a['account_type']==='wallet'?'selected':''?>>Billetera digital</option>
              <option value="cash" <?=$a['account_type']==='cash'?'selected':''?>>Efectivo</option>
              <option value="other" <?=$a['account_type']==='other'?'selected':''?>>Otra</option>
            </select>
          </div>
          <div>
            <label>Icono</label>
            <input class="emoji-edit clean-input" name="icon" value="<?=e($a['icon'])?>">
          </div>
          <div>
            <label>Color</label>
            <div class="account-color-wrap">
              <input type="color" name="color" value="<?=e($a['color'] ?: '#111827')?>">
            </div>
          </div>
          <div class="full">
            <label>Corregir saldo actual <small>Si tu banco muestra otro monto, cámbialo aquí.</small></label>
            <div class="settings-money-input compact-money-input">
              <span>S/</span>
              <input name="current_balance" type="number" step="0.01" value="<?=e(number_format((float)$a['balance'],2,'.',''))?>">
            </div>
          </div>
        </div>
      </details>
    </form>
    <?php endforeach; ?>
  </div>

  <section class="simple-action-banner cleaner-banner">
    <span class="simple-action-banner-icon">↔</span>
    <div>
      <h2>Pasa dinero entre tus cuentas sin afectar ingresos ni gastos</h2>
      <p>Ideal para mover saldo de Interbank a Yape, efectivo o cualquier otra cuenta tuya.</p>
    </div>
    <a class="btn primary" href="<?=e(app_url('dashboard?action=transfer'))?>">Mover dinero</a>
  </section>

  <section class="finance-panel form-panel account-create-panel is-hidden" id="addAccountPanel">
    <div class="section-heading">
      <div>
        <h2>Nueva cuenta</h2>
        <p>Agrega una cuenta bancaria, billetera digital o efectivo por separado.</p>
      </div>
      <button class="btn ghost" type="button" data-close-add-account>Cerrar</button>
    </div>
    <form id="newAccountForm" class="settings-form">
      <div class="form-grid">
        <div>
          <label>¿Cómo la llamamos?</label>
          <input name="name" required placeholder="Ej. BCP Andrés">
        </div>
        <div>
          <label>Tipo</label>
          <select name="account_type">
            <option value="bank">Cuenta bancaria</option>
            <option value="wallet">Billetera digital</option>
            <option value="cash">Efectivo</option>
            <option value="other">Otra</option>
          </select>
        </div>
        <div>
          <label>Icono</label>
          <input name="icon" value="🏦">
        </div>
        <div>
          <label>¿Cuánto tiene ahora?</label>
          <div class="settings-money-input compact-money-input"><span>S/</span><input name="current_balance" type="number" step="0.01" value="0.00"></div>
        </div>
      </div>
      <input type="hidden" name="color" value="#111827">
      <button class="btn primary">Crear cuenta</button>
    </form>
  </section>
</div>
<script>
document.addEventListener('click', function(e){
  const openBtn = e.target.closest('[data-toggle-add-account]');
  if(openBtn){
    document.getElementById('addAccountPanel')?.classList.remove('is-hidden');
    document.getElementById('addAccountPanel')?.scrollIntoView({behavior:'smooth', block:'start'});
  }
  const closeBtn = e.target.closest('[data-close-add-account]');
  if(closeBtn){
    document.getElementById('addAccountPanel')?.classList.add('is-hidden');
  }
  const editBtn = e.target.closest('[data-open-account-edit]');
  if(editBtn){
    const card = editBtn.closest('.account-clean-card');
    const details = card?.querySelector('[data-account-edit-box]');
    if(details){ details.open = true; details.scrollIntoView({behavior:'smooth', block:'nearest'}); }
    const menu = editBtn.closest('.account-clean-menu');
    if(menu) menu.open = false;
  }
});
</script>
<script src="<?=e(app_url('assets/js/finance-tools.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/finance-tools.js'))?>"></script>
<?php page_bottom(); ?>
