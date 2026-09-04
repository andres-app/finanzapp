<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__.'/../app/bootstrap.php';
$userId = isset($argv[1]) ? max(1,(int)$argv[1]) : 1;
FinanceSchema::ensure($userId);

$checks=[];
function audit_scalar(string $sql, array $params=[]): int {
    $st=db()->prepare($sql); $st->execute($params); return (int)$st->fetchColumn();
}
function audit_check(string $name, int $count, string $severity='error'): void {
    global $checks; $checks[]=['name'=>$name,'count'=>$count,'severity'=>$severity];
}

audit_check('Transacciones con categoría de otro usuario', audit_scalar("SELECT COUNT(*) FROM transactions t JOIN categories c ON c.id=t.category_id WHERE t.user_id=? AND c.user_id<>t.user_id",[$userId]));
audit_check('Tipo ingreso/egreso incompatible con categoría', audit_scalar("SELECT COUNT(*) FROM transactions t JOIN categories c ON c.id=t.category_id WHERE t.user_id=? AND c.type<>'both' AND c.type<>t.type",[$userId]));
audit_check('Conceptos vinculados a categoría distinta', audit_scalar("SELECT COUNT(*) FROM transactions t JOIN concepts co ON co.id=t.concept_id WHERE t.user_id=? AND co.category_id<>t.category_id",[$userId]));
audit_check('Transacciones con cuenta de otro usuario', audit_scalar("SELECT COUNT(*) FROM transactions t JOIN financial_accounts a ON a.id=t.account_id WHERE t.user_id=? AND a.user_id<>t.user_id",[$userId]));
audit_check('Transacciones con fondo de otro usuario', audit_scalar("SELECT COUNT(*) FROM transactions t JOIN funds f ON f.id=t.fund_id WHERE t.user_id=? AND f.user_id<>t.user_id",[$userId]));
audit_check('Obligaciones fantasma anteriores al alta del pago fijo', audit_scalar("SELECT COUNT(*) FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id WHERE mp.user_id=? AND mp.status='pending' AND mp.transaction_id IS NULL AND mp.period<DATE_FORMAT(r.created_at,'%Y-%m')",[$userId]));
audit_check('Expectativas de ingreso anteriores al alta del ingreso fijo', audit_scalar("SELECT COUNT(*) FROM monthly_income_expectations mie JOIN recurring_incomes r ON r.id=mie.recurring_id WHERE mie.user_id=? AND mie.period<DATE_FORMAT(r.created_at,'%Y-%m')",[$userId]));
audit_check('Pagos marcados pagados sin egreso válido', audit_scalar("SELECT COUNT(*) FROM monthly_payments mp LEFT JOIN transactions t ON t.id=mp.transaction_id AND t.user_id=mp.user_id WHERE mp.user_id=? AND mp.status='paid' AND (t.id IS NULL OR t.type<>'expense')",[$userId]));
audit_check('Pagos pagados con transacción de concepto/categoría distinta', audit_scalar("SELECT COUNT(*) FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id JOIN transactions t ON t.id=mp.transaction_id WHERE mp.user_id=? AND mp.status='paid' AND (t.category_id<>r.category_id OR COALESCE(t.concept_id,0)<>COALESCE(r.concept_id,0))",[$userId]));
audit_check('Fondos con saldo negativo', audit_scalar("SELECT COUNT(*) FROM (SELECT f.id,COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=f.user_id AND fa.fund_id=f.id),0)-COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense'),0) available FROM funds f WHERE f.user_id=?) x WHERE x.available < -0.005",[$userId]));
audit_check('Cuentas con saldo negativo', audit_scalar("SELECT COUNT(*) FROM financial_accounts a WHERE a.user_id=? AND (a.opening_balance + COALESCE((SELECT SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END) FROM transactions t WHERE t.user_id=a.user_id AND t.account_id=a.id),0) + COALESCE((SELECT SUM(ad.amount) FROM account_adjustments ad WHERE ad.user_id=a.user_id AND ad.account_id=a.id),0) + COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.to_account_id=a.id),0) - COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.from_account_id=a.id),0)) < -0.005",[$userId]));
audit_check('Transferencias con cuentas ajenas o mismo origen/destino', audit_scalar("SELECT COUNT(*) FROM account_transfers tr JOIN financial_accounts a1 ON a1.id=tr.from_account_id JOIN financial_accounts a2 ON a2.id=tr.to_account_id WHERE tr.user_id=? AND (a1.user_id<>tr.user_id OR a2.user_id<>tr.user_id OR tr.from_account_id=tr.to_account_id)",[$userId]));
audit_check('Asignaciones de fondo con fondo ajeno', audit_scalar("SELECT COUNT(*) FROM fund_allocations fa JOIN funds f ON f.id=fa.fund_id WHERE fa.user_id=? AND f.user_id<>fa.user_id",[$userId]));
audit_check('Asignaciones ligadas a un movimiento que no es ingreso propio', audit_scalar("SELECT COUNT(*) FROM fund_allocations fa JOIN transactions t ON t.id=fa.source_transaction_id WHERE fa.user_id=? AND (t.user_id<>fa.user_id OR t.type<>'income')",[$userId]));
audit_check('Ingresos sobreasignados a fondos', audit_scalar("SELECT COUNT(*) FROM (SELECT t.id,t.amount,COALESCE(SUM(fa.amount),0) allocated FROM transactions t JOIN fund_allocations fa ON fa.source_transaction_id=t.id AND fa.user_id=t.user_id WHERE t.user_id=? AND t.type='income' GROUP BY t.id,t.amount HAVING allocated>t.amount+0.005) x",[$userId]));
audit_check('Pagos fijos vinculados a fondo de otro usuario', audit_scalar("SELECT COUNT(*) FROM recurring_payments r JOIN funds f ON f.id=r.fund_id WHERE r.user_id=? AND f.user_id<>r.user_id",[$userId]));
audit_check('Ingresos fijos vinculados a cuenta de otro usuario', audit_scalar("SELECT COUNT(*) FROM recurring_incomes r JOIN financial_accounts a ON a.id=r.account_id WHERE r.user_id=? AND a.user_id<>r.user_id",[$userId]));

// No es un error contable, pero sí una alerta operativa: el correo solicitado por
// el usuario no está saliendo si los últimos intentos fallan.
$failedMail=audit_scalar("SELECT COUNT(*) FROM (SELECT status FROM email_log WHERE user_id=? ORDER BY id DESC LIMIT 10) x WHERE status='failed'",[$userId]);
audit_check('Últimos correos de notificación fallidos (máx. 10)',$failedMail,'warning');

$errors=array_sum(array_map(fn($c)=>$c['severity']==='error'?$c['count']:0,$checks));
$warnings=array_sum(array_map(fn($c)=>$c['severity']==='warning'?$c['count']:0,$checks));
echo "MI DINERO · AUDITORÍA DE INTEGRIDAD · usuario {$userId}\n";
echo str_repeat('=',76)."\n";
foreach($checks as $c){
    $prefix=$c['count']===0?'[OK]   ':($c['severity']==='warning'?'[AVISO]':'[FALLO]');
    echo $prefix.' '.$c['name']; if($c['count'])echo ' -> '.$c['count']; echo "\n";
}
echo str_repeat('-',76)."\n";
if($errors===0) echo "RESULTADO: integridad contable básica OK".($warnings?"; {$warnings} aviso(s) operativo(s).":".")."\n";
else echo "RESULTADO: se detectaron {$errors} inconsistencia(s) crítica(s)".($warnings?" y {$warnings} aviso(s).":".")."\n";
