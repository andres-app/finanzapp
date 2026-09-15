<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();FinanceSchema::ensure($uid);require __DIR__.'/../app/layout.php';
$period=trim((string)($_GET['period']??date('Y-m')));if(!preg_match('/^\d{4}-\d{2}$/',$period))$period=date('Y-m');
$actor=(int)($_GET['actor']??0);
$rows=FinanceAudit::list($uid,['period'=>$period,'actor_user_id'=>$actor,'limit'=>220]);
$members=FinanceAudit::members($uid);
$closure=MonthCloseService::get($uid,$period);

function audit_entity_label(string $type): string {
    switch($type){
        case 'transaction': return 'Movimiento';
        case 'account_transfer': return 'Transferencia';
        case 'account_adjustment': return 'Ajuste de cuenta';
        case 'account': return 'Cuenta';
        case 'fund': return 'Fondo';
        case 'fund_allocation': case 'fund_allocation_group': return 'Fondos';
        case 'monthly_payment': case 'recurring_payment': return 'Pago fijo';
        case 'recurring_income': return 'Ingreso fijo';
        case 'savings_movement': case 'savings_goal': return 'Ahorro';
        case 'concept': return 'Concepto';
        case 'category': return 'Categoría';
        case 'goal': return 'Meta';
        case 'household': case 'household_member': return 'Hogar';
        case 'monthly_closure': return 'Cierre mensual';
        case 'audit': return 'Auditoría';
        default: return 'Sistema';
    }
}
function audit_icon(string $action,string $entity): string {
    if($action==='operation_reversed')return '↶';
    if(strpos($action,'payment')!==false)return '📌';
    if(strpos($action,'savings')!==false)return '🐷';
    if(strpos($action,'fund')!==false)return '◎';
    if(strpos($action,'transfer')!==false)return '↔';
    if(strpos($action,'account')!==false)return '🏦';
    if(strpos($action,'month_')!==false)return '▣';
    if(in_array($entity,['household','household_member'],true))return '⌂';
    if($entity==='transaction')return '↕';
    return '•';
}
function audit_diff(array $before,array $after): string {
    $priority=['amount','target_amount','name','due_date','status','paid_amount','notify_email'];
    foreach($priority as $key){
        if(array_key_exists($key,$before)&&array_key_exists($key,$after)&&(string)$before[$key]!== (string)$after[$key]){
            $a=$before[$key];$b=$after[$key];
            if(in_array($key,['amount','target_amount','paid_amount'],true))return 'Antes S/ '.number_format((float)$a,2).' → ahora S/ '.number_format((float)$b,2);
            return 'Antes: '.(string)$a.' → ahora: '.(string)$b;
        }
    }
    return '';
}
page_top('Actividad','actividad');
?>
<div class="page-wrap audit-page">
  <div class="page-head audit-page-head">
    <div><span class="eyebrow">TRAZABILIDAD DEL HOGAR</span><h1>Actividad</h1><p>Consulta quién hizo cada cambio y anula operaciones compatibles sin borrar el historial.</p></div>
    <a class="btn primary audit-close-link" href="<?=e(app_url('cierre?period='.$period))?>">Cierre mensual</a>
  </div>

  <div class="audit-toolbar-card">
    <form class="audit-filters" data-spa-filter method="get" action="<?=e(app_url('actividad'))?>">
      <label><span>Mes</span><input type="month" name="period" value="<?=e($period)?>"></label>
      <label><span>Usuario</span><select name="actor"><option value="0">Todos</option><?php foreach($members as $m):?><option value="<?=e($m['user_id'])?>" <?=$actor===(int)$m['user_id']?'selected':''?>><?=e($m['name'])?></option><?php endforeach;?></select></label>
      <button class="btn" type="submit">Aplicar</button>
    </form>
    <div class="audit-period-status">
      <?php if($closure && !empty($closure['is_closed'])):?><span class="audit-status-chip closed">▣ Mes cerrado</span><small>Fotografía guardada <?=e(date('d/m/Y H:i',strtotime($closure['closed_at'])))?></small>
      <?php elseif($closure):?><span class="audit-status-chip reopened">↺ Mes reabierto</span><small>El cierre histórico sigue disponible.</small>
      <?php else:?><span class="audit-status-chip open">● Mes abierto</span><small>Los cambios se siguen registrando normalmente.</small><?php endif;?>
    </div>
  </div>

  <div class="audit-list-card">
    <div class="table-card-head"><strong>Registro de actividad</strong><span><?=count($rows)?> eventos</span></div>
    <div class="audit-list">
      <?php foreach($rows as $r): $diff=audit_diff($r['before'],$r['after']); $reversed=!empty($r['reversed_at']); ?>
      <article class="audit-item <?=$reversed?'is-reversed':''?>">
        <div class="audit-item-icon"><?=e(audit_icon((string)$r['action'],(string)$r['entity_type']))?></div>
        <div class="audit-item-body">
          <div class="audit-item-title-row"><strong><?=e($r['title'])?></strong><span class="audit-entity"><?=e(audit_entity_label((string)$r['entity_type']))?></span></div>
          <?php if(!empty($r['summary'])):?><p><?=e($r['summary'])?></p><?php endif;?>
          <?php if($diff!==''):?><small class="audit-diff"><?=e($diff)?></small><?php endif;?>
          <small class="audit-meta"><?=e($r['actor_name']?:'Usuario')?> · <?=e(date('d/m/Y · H:i',strtotime($r['created_at'])))?></small>
          <?php if($reversed):?><small class="audit-reversed-note">Anulado <?=e(date('d/m/Y H:i',strtotime($r['reversed_at'])))?><?=!empty($r['reversed_by_name'])?' por '.e($r['reversed_by_name']):''?></small><?php endif;?>
        </div>
        <div class="audit-item-actions">
          <?php if(!empty($r['reversible'])&&!$reversed):?><button type="button" class="audit-revert-btn" data-audit-revert="<?=e($r['id'])?>" data-audit-title="<?=e($r['title'])?>">Anular</button><?php elseif($reversed):?><span class="audit-status-chip reversed">Anulado</span><?php else:?><span class="audit-lock">Histórico</span><?php endif;?>
        </div>
      </article>
      <?php endforeach;?>
      <?php if(!$rows):?><div class="empty audit-empty">No hay actividad registrada para estos filtros.</div><?php endif;?>
    </div>
  </div>
</div>

<div class="modal audit-modal" id="auditRevertModal" aria-hidden="true">
 <div class="modal-card audit-modal-card">
   <button class="modal-x" type="button" data-audit-close>×</button>
   <span class="eyebrow">CORRECCIÓN SEGURA</span><h3>Anular operación</h3>
   <p id="auditRevertTitle">La operación dejará de afectar saldos, pero seguirá visible en la auditoría.</p>
   <form id="auditRevertForm">
    <input type="hidden" name="audit_id" id="auditRevertId">
    <label>Motivo de la corrección<textarea name="reason" rows="3" maxlength="255" placeholder="Ej.: se registró en la cuenta equivocada">Corrección de registro</textarea></label>
    <div class="modal-actions"><button type="button" class="btn" data-audit-close>Cancelar</button><button type="submit" class="btn danger">Anular operación</button></div>
   </form>
 </div>
</div>
<script src="<?=e(app_url('assets/js/audit.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/audit.js'))?>"></script>
<?php page_bottom(); ?>
