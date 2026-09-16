<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
require __DIR__.'/../app/layout.php';

/**
 * Planificador de solo lectura.
 * No ejecuta migraciones ni sincronizaciones al abrir la pantalla. Lee la
 * estructura disponible del hosting y se adapta a columnas opcionales.
 */
$plannerCriticalWarning=false;

$plannerTableExists=function(string $table): bool {
    try {
        $st=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch(Throwable $e) { return false; }
};
$plannerColumnExists=function(string $table,string $column): bool {
    try {
        $st=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$table,$column]);
        return (bool)$st->fetchColumn();
    } catch(Throwable $e) { return false; }
};

// 1) Saldos de cuentas. La consulta se arma únicamente con tablas/columnas
// realmente existentes para evitar que una migración incompleta bloquee la vista.
$accounts=[];
try {
    if($plannerTableExists('financial_accounts')){
        $opening=$plannerColumnExists('financial_accounts','opening_balance')?'a.opening_balance':'0';
        $activeWhere=$plannerColumnExists('financial_accounts','active')?' AND a.active=1':'';
        $accountType=$plannerColumnExists('financial_accounts','account_type')?'a.account_type':"'bank' account_type";
        $icon=$plannerColumnExists('financial_accounts','icon')?'a.icon':"'🏦' icon";
        $color=$plannerColumnExists('financial_accounts','color')?'a.color':"'#111827' color";

        $txExpr='';
        if($plannerTableExists('transactions') && $plannerColumnExists('transactions','account_id')){
            $txVoid=$plannerColumnExists('transactions','voided_at')?' AND t.voided_at IS NULL':'';
            $txExpr=" + COALESCE((SELECT SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END) FROM transactions t WHERE t.user_id=a.user_id AND t.account_id=a.id{$txVoid}),0)";
        }
        $adjustExpr='';
        if($plannerTableExists('account_adjustments') && $plannerColumnExists('account_adjustments','account_id')){
            $adVoid=$plannerColumnExists('account_adjustments','voided_at')?' AND ad.voided_at IS NULL':'';
            $adjustExpr=" + COALESCE((SELECT SUM(ad.amount) FROM account_adjustments ad WHERE ad.user_id=a.user_id AND ad.account_id=a.id{$adVoid}),0)";
        }
        $transferIn='';$transferOut='';
        if($plannerTableExists('account_transfers') && $plannerColumnExists('account_transfers','from_account_id') && $plannerColumnExists('account_transfers','to_account_id')){
            $trVoid=$plannerColumnExists('account_transfers','voided_at')?' AND tr.voided_at IS NULL':'';
            $transferIn=" + COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.to_account_id=a.id{$trVoid}),0)";
            $transferOut=" - COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.from_account_id=a.id{$trVoid}),0)";
        }
        $sql="SELECT a.id,a.name,{$accountType},{$icon},{$color},{$opening} opening_balance,
              ({$opening}{$txExpr}{$adjustExpr}{$transferIn}{$transferOut}) balance
              FROM financial_accounts a WHERE a.user_id=?{$activeWhere} ORDER BY a.id";
        $st=db()->prepare($sql);$st->execute([$uid]);$accounts=$st->fetchAll();
    }
} catch(Throwable $e){
    $plannerCriticalWarning=true;
    error_log('[Finanzapp planificador cuentas] '.$e->getMessage());
    $accounts=[];
}

// 2) Ahorro protegido. Se lee sin crear fondos ni ejecutar SavingsSchema::ensure().
$protected=[];
try {
    if($accounts && $plannerTableExists('funds') && $plannerTableExists('fund_allocations')){
        $fundActive=$plannerColumnExists('funds','active')?' AND active=1':'';
        $fundSt=db()->prepare("SELECT id FROM funds WHERE user_id=?{$fundActive} AND (LOWER(TRIM(name))='ahorro' OR name LIKE '__SAV7__%') ORDER BY id LIMIT 1");
        $fundSt->execute([$uid]);$savingsFundId=(int)($fundSt->fetchColumn()?:0);
        if($savingsFundId>0){
            $faVoid=$plannerColumnExists('fund_allocations','voided_at')?' AND voided_at IS NULL':'';
            $st=db()->prepare("SELECT amount,note FROM fund_allocations WHERE user_id=? AND fund_id=?{$faVoid} ORDER BY id");
            $st->execute([$uid,$savingsFundId]);
            foreach($st->fetchAll() as $row){
                $m=SavingsSchema::parseMarker($row['note']??'');
                if(empty($m['tracked'])) continue;
                $amount=(float)$row['amount'];
                $aid=$amount>=0?(int)$m['to_account_id']:(int)$m['from_account_id'];
                if($aid<=0) continue;
                $protected[$aid]=($protected[$aid]??0.0)+$amount;
            }
            foreach($protected as $aid=>$amount){
                $protected[$aid]=max(0.0,round((float)$amount,2));
                if($protected[$aid]<0.005) unset($protected[$aid]);
            }
        }
    }
} catch(Throwable $e){
    // El ahorro protegido es un dato complementario: no debe poner la pantalla
    // en "modo compatible" si el saldo real de las cuentas sí pudo leerse.
    error_log('[Finanzapp planificador ahorro protegido] '.$e->getMessage());
    $protected=[];
}

$totalCash=0.0;
foreach($accounts as &$a){
    $a['balance']=(float)($a['balance']??0);
    $totalCash+=$a['balance'];
    $a['savings_reserved']=max(0,(float)($protected[(int)$a['id']]??0));
    $a['spendable_balance']=max(0,$a['balance']-$a['savings_reserved']);
}
unset($a);

// 3) Compromisos pendientes. Solo lectura: no se llama a ensureMonthlyPayments().
$period=date('Y-m');
$pendingTotal=0.0;
try {
    if($plannerTableExists('monthly_payments')){
        $currentMonthEnd=(new DateTimeImmutable($period.'-01'))->modify('+1 month')->format('Y-m-d');
        $hasPaid=$plannerColumnExists('monthly_payments','paid_amount');
        $hasStatus=$plannerColumnExists('monthly_payments','status');
        $hasDue=$plannerColumnExists('monthly_payments','due_date');
        if($hasStatus && $hasDue){
            if($hasPaid){
                $q=db()->prepare("SELECT COALESCE(SUM(GREATEST(amount-COALESCE(paid_amount,0),0)),0)
                    FROM monthly_payments
                    WHERE user_id=? AND status IN ('pending','partial') AND due_date<?
                      AND amount-COALESCE(paid_amount,0)>0.005");
            }else{
                $q=db()->prepare("SELECT COALESCE(SUM(amount),0)
                    FROM monthly_payments
                    WHERE user_id=? AND status='pending' AND due_date<?");
            }
            $q->execute([$uid,$currentMonthEnd]);
            $pendingTotal=(float)$q->fetchColumn();
        }
    }
} catch(Throwable $e){
    // Mantener el planificador utilizable aun si el módulo de compromisos está
    // en una versión anterior; el saldo de cuentas continúa siendo válido.
    error_log('[Finanzapp planificador compromisos] '.$e->getMessage());
    $pendingTotal=0.0;
}

$bootstrap=[
    'total_cash'=>$totalCash,
    'pending'=>$pendingTotal,
    'after_commitments'=>$totalCash-$pendingTotal,
    'accounts'=>$accounts,
    'threshold'=>1000
];

page_top('Planificador de compras','planificador');
?>
<div class="page-wrap planner-page">
  <?php if($plannerCriticalWarning): ?>
  <div class="calendar-soft-warning planner-soft-warning"><b>No pudimos leer todas tus cuentas.</b><span>El planificador sigue disponible, pero conviene revisar la conexión de datos antes de tomar una decisión con esta simulación.</span></div>
  <?php endif; ?>
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
