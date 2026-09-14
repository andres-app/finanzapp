<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
require __DIR__.'/../app/FinanceService.php';
$period=$_GET['period']??date('Y-m');

/**
 * Generación defensiva de obligaciones mensuales.
 * No depende de FinanceSchema ni de tablas auxiliares: si el dashboard cae al
 * modo compatible, los pagos fijos del mes siguen existiendo y se muestran.
 */
function ensure_monthly_payments_safe(int $uid, string $period): void {
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
    try {
        [$y,$m] = array_map('intval', explode('-', $period));
        if ($m < 1 || $m > 12 || $y < 2000) return;
        $days = cal_days_in_month(CAL_GREGORIAN, $m, $y);

        $q = db()->prepare("SELECT id,amount,due_day,category_id,concept_id,name FROM recurring_payments
            WHERE user_id=? AND active=1 AND DATE_FORMAT(created_at,'%Y-%m')<=?");
        $q->execute([$uid,$period]);

        $hasAny=db()->prepare("SELECT COUNT(*) FROM monthly_payments
            WHERE user_id=? AND recurring_id=? AND period=?");
        $hasPaid=db()->prepare("SELECT paid_at,transaction_id FROM monthly_payments
            WHERE user_id=? AND recurring_id=? AND period=? AND status='paid'
            ORDER BY id DESC LIMIT 1");
        $closeDup=db()->prepare("UPDATE monthly_payments
            SET status='paid',paid_at=?,transaction_id=?
            WHERE user_id=? AND recurring_id=? AND period=? AND status='pending'");
        $updatePending=db()->prepare("UPDATE monthly_payments SET due_date=?,amount=?
            WHERE user_id=? AND recurring_id=? AND period=? AND status='pending'");
        $findPaymentTx=db()->prepare("SELECT t.id,t.occurred_at FROM transactions t
            WHERE t.user_id=? AND t.type='expense' AND t.category_id=? AND t.concept_id=?
              AND t.occurred_at>=? AND t.occurred_at<?
              AND NOT EXISTS (
                SELECT 1 FROM monthly_payments used_mp
                WHERE used_mp.user_id=t.user_id AND used_mp.transaction_id=t.id AND used_mp.status='paid'
              )
            ORDER BY (t.description=? ) DESC,t.id DESC LIMIT 1");
        $insert=db()->prepare("INSERT INTO monthly_payments
            (user_id,recurring_id,period,due_date,amount,status,created_at)
            VALUES(?,?,?,?,?,'pending',NOW())");
        $insertPaid=db()->prepare("INSERT INTO monthly_payments
            (user_id,recurring_id,period,due_date,amount,status,paid_at,transaction_id,created_at)
            VALUES(?,?,?,?,?,'paid',?,?,NOW())");

        foreach ($q->fetchAll() as $r) {
            $rid=(int)$r['id'];
            $day=max(1,min((int)$r['due_day'],$days));
            $due=sprintf('%04d-%02d-%02d',$y,$m,$day);

            $hasPaid->execute([$uid,$rid,$period]);
            $paid=$hasPaid->fetch();
            if($paid){
                $closeDup->execute([$paid['paid_at'],$paid['transaction_id'],$uid,$rid,$period]);
                continue;
            }

            $periodStart=$period.'-01 00:00:00';
            $periodEnd=(new DateTimeImmutable($period.'-01'))->modify('+1 month')->format('Y-m-d H:i:s');
            $findPaymentTx->execute([
                $uid,(int)$r['category_id'],(int)$r['concept_id'],
                $periodStart,$periodEnd,'Pago mensual: '.$r['name']
            ]);
            $tx=$findPaymentTx->fetch();

            $hasAny->execute([$uid,$rid,$period]);
            if((int)$hasAny->fetchColumn()>0){
                if($tx){
                    $closeDup->execute([$tx['occurred_at'],(int)$tx['id'],$uid,$rid,$period]);
                    continue;
                }
                $updatePending->execute([$due,(float)$r['amount'],$uid,$rid,$period]);
                continue;
            }

            if($tx){
                $insertPaid->execute([$uid,$rid,$period,$due,(float)$r['amount'],$tx['occurred_at'],(int)$tx['id']]);
                continue;
            }

            // No dependemos de que el hosting tenga el índice UNIQUE actualizado.
            // Solo insertamos si no existe ninguna fila de ese pago fijo y período.
            $insert->execute([$uid,$rid,$period,$due,(float)$r['amount']]);
        }
    } catch (Throwable $e) {
        error_log('[finanzas ensure_monthly_payments_safe] '.$e->getMessage());
    }
}

// Generar tanto el periodo visualizado como el mes actual. Esto ocurre antes
// del cálculo principal y antes de cualquier fallback.
ensure_monthly_payments_safe($uid,$period);
$currentPeriodForPayments=date('Y-m');
if ($currentPeriodForPayments !== $period) {
    ensure_monthly_payments_safe($uid,$currentPeriodForPayments);
}

try {
    json_response(['ok'=>true,'data'=>FinanceService::dashboard($uid,$period)]);
} catch (Throwable $e) {
    error_log('[finanzas dashboard] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());

    // Fallback de emergencia: el dashboard nunca debe quedar visualmente en cero
    // por una tabla auxiliar o migración nueva. Calcula con el núcleo histórico.
    try {
        [$start,$end]=month_range($period);
        $startDt=new DateTimeImmutable($start);
        $prevPeriod=$startDt->modify('-1 month')->format('Y-m');
        [$pStart,$pEnd]=month_range($prevPeriod);

        $sum=db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income,
            COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense,
            COALESCE(SUM(CASE WHEN type='expense' AND is_ant_expense=1 THEN amount ELSE 0 END),0) ant
            FROM transactions WHERE user_id=? AND occurred_at>=? AND occurred_at<?");
        $sum->execute([$uid,$start,$end]);$cur=$sum->fetch()?:['income'=>0,'expense'=>0,'ant'=>0];
        $sum->execute([$uid,$pStart,$pEnd]);$prev=$sum->fetch()?:['income'=>0,'expense'=>0,'ant'=>0];

        $openingQ=db()->prepare("SELECT
            COALESCE((SELECT SUM(opening_balance) FROM financial_accounts WHERE user_id=? AND active=1),0)
            + COALESCE((SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) FROM transactions WHERE user_id=? AND occurred_at<?),0)");
        $openingQ->execute([$uid,$uid,$start]);
        $opening=(float)$openingQ->fetchColumn();

        $accountsQ=db()->prepare("SELECT a.id,a.name,a.account_type,a.icon,a.color,a.opening_balance,
            a.opening_balance
            + COALESCE((SELECT SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END) FROM transactions t WHERE t.user_id=a.user_id AND t.account_id=a.id),0)
            + COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.to_account_id=a.id),0)
            - COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.from_account_id=a.id),0) balance
            FROM financial_accounts a WHERE a.user_id=? AND a.active=1 ORDER BY a.id");
        $accountsQ->execute([$uid]);$accounts=$accountsQ->fetchAll();

        $fundsQ=db()->prepare("SELECT f.id,f.name,f.icon,f.color,f.target_amount,
            CASE WHEN LOWER(TRIM(f.name))='ahorro' OR f.name LIKE '__SAV7__%' THEN -1 ELSE NULL END savings_goal_id,
            COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=f.user_id AND fa.fund_id=f.id),0) allocated_net,
            COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense'),0) spent,
            COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=f.user_id AND fa.fund_id=f.id),0)
             - COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense'),0) available
            FROM funds f WHERE f.user_id=? AND f.active=1 ORDER BY f.id");
        $fundsQ->execute([$uid]);$funds=$fundsQ->fetchAll();

        $cat=db()->prepare("SELECT c.id,c.name,c.icon,c.color,SUM(t.amount) total
            FROM transactions t JOIN categories c ON c.id=t.category_id
            WHERE t.user_id=? AND t.type='expense' AND t.occurred_at>=? AND t.occurred_at<?
            GROUP BY c.id,c.name,c.icon,c.color ORDER BY total DESC LIMIT 8");
        $cat->execute([$uid,$start,$end]);

        $daily=db()->prepare("SELECT DATE(occurred_at) d,
            COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income,
            COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense,
            0 adjustment
            FROM transactions WHERE user_id=? AND occurred_at>=? AND occurred_at<?
            GROUP BY DATE(occurred_at) ORDER BY d");
        $daily->execute([$uid,$start,$end]);

        $ant=db()->prepare("SELECT COALESCE(co.name,c.name) name,c.icon,SUM(t.amount) total,COUNT(*) qty
            FROM transactions t JOIN categories c ON c.id=t.category_id LEFT JOIN concepts co ON co.id=t.concept_id
            WHERE t.user_id=? AND t.type='expense' AND t.is_ant_expense=1 AND t.occurred_at>=? AND t.occurred_at<?
            GROUP BY name,c.icon ORDER BY total DESC LIMIT 6");
        $ant->execute([$uid,$start,$end]);

        $currentPeriod=date('Y-m');
        $pendingQ=db()->prepare("SELECT mp.id,mp.period,mp.due_date,mp.amount,mp.status,r.name,r.icon,r.fund_id,DATEDIFF(mp.due_date,CURDATE()) days_left
            FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
            WHERE mp.user_id=? AND mp.status='pending' AND mp.period=? ORDER BY mp.due_date ASC");
        $pendingQ->execute([$uid,$currentPeriod]);$pendingCurrent=$pendingQ->fetchAll();

        $selectedPendingQ=db()->prepare("SELECT mp.id,mp.due_date,mp.amount,mp.status,r.name,r.icon,r.fund_id,DATEDIFF(mp.due_date,CURDATE()) days_left
            FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
            WHERE mp.user_id=? AND mp.period=? AND mp.status='pending' ORDER BY mp.due_date ASC");
        $selectedPendingQ->execute([$uid,$period]);$pending=$selectedPendingQ->fetchAll();

        $recent=db()->prepare("SELECT t.id,t.type,t.amount,t.occurred_at,t.description,t.is_ant_expense,c.name category,c.icon,co.name concept,a.name account,f.name fund
            FROM transactions t JOIN categories c ON c.id=t.category_id LEFT JOIN concepts co ON co.id=t.concept_id
            LEFT JOIN financial_accounts a ON a.id=t.account_id LEFT JOIN funds f ON f.id=t.fund_id
            WHERE t.user_id=? ORDER BY t.id DESC LIMIT 10");
        $recent->execute([$uid]);

        $income=(float)$cur['income'];$expense=(float)$cur['expense'];$monthNet=$income-$expense;
        $pi=(float)$prev['income'];$pe=(float)$prev['expense'];
        $pct=function($a,$b){return $b==0.0?($a==0.0?0:100):(($a-$b)/abs($b))*100;};
        $totalCash=0.0;foreach($accounts as $a)$totalCash+=(float)$a['balance'];
        $reserved=0.0;$operationalReserved=0.0;$savingsReserved=0.0;
        foreach($funds as $f){$av=max(0,(float)$f['available']);$reserved+=$av;if(!empty($f['savings_goal_id']))$savingsReserved+=$av;else$operationalReserved+=$av;}
        $unallocated=$totalCash-$reserved;
        $pendingTotal=0.0;foreach($pendingCurrent as $r)$pendingTotal+=(float)$r['amount'];

        // Evitar doble descuento: si un pago pendiente ya está cubierto por su fondo
        // asociado, ese mismo dinero no puede restarse otra vez como deuda descubierta.
        $fundAvailableMap=[];
        foreach($funds as $f)$fundAvailableMap[(int)$f['id']]=max(0,(float)$f['available']);
        $dueByFund=[];
        foreach($pendingCurrent as $pRow){
            if(!empty($pRow['fund_id'])){
                $fid=(int)$pRow['fund_id'];
                $dueByFund[$fid]=($dueByFund[$fid]??0)+(float)$pRow['amount'];
            }
        }
        $fundedCoverage=0.0;
        foreach($dueByFund as $fid=>$due)$fundedCoverage+=min($due,$fundAvailableMap[$fid]??0.0);
        $uncoveredPending=max(0,$pendingTotal-$fundedCoverage);
        // Los pagos pendientes no descuentan el saldo: solo un pago/gasto registrado lo hace.
        $free=$unallocated;
        $nextPeriod=$startDt->modify('+1 month')->format('Y-m');

        // El cálculo compatible también debe conservar el módulo de Ahorro.
        // Si el dashboard principal cayó por una función no relacionada, no debemos
        // hacer creer al usuario que sus metas desaparecieron.
        $savingsSafe=['goals'=>[],'total_saved'=>0,'total_target'=>0,'remaining'=>0,'progress'=>0];
        try {
            $savingsSafe=FinanceService::savingsOverview($uid);
        } catch (Throwable $savingsError) {
            error_log('[finanzas dashboard fallback savings] '.$savingsError->getMessage());
            // Respaldo mínimo: al menos mostrar las metas configuradas aunque aún no
            // podamos calcular su trazabilidad completa.
            try {
                $sg=db()->prepare("SELECT id,name,period,target_amount FROM goals WHERE user_id=? AND type='savings' ORDER BY period,id");
                $sg->execute([$uid]);
                $safeGoals=[];$target=0.0;
                foreach($sg->fetchAll() as $g){
                    $t=max(0,(float)$g['target_amount']);$target+=$t;
                    $g['saved_amount']=0.0;$g['remaining_amount']=$t;$g['progress']=0.0;$g['account_name']=null;
                    $safeGoals[]=$g;
                }
                $savingsSafe=['goals'=>$safeGoals,'total_saved'=>0,'total_target'=>$target,'remaining'=>$target,'progress'=>0];
            } catch (Throwable $ignored) {}
        }

        $data=[
            'period'=>$period,'previous_period'=>$prevPeriod,'next_period'=>$nextPeriod,'current_period'=>$currentPeriod,
            'summary'=>[
                'income'=>$income,'expense'=>$expense,'balance'=>$monthNet,'adjustment'=>0,'ant'=>(float)$cur['ant'],
                'opening_balance'=>$opening,'closing_balance'=>$opening+$monthNet,'total_cash'=>$totalCash,'available_in_accounts'=>$totalCash,
                'reserved'=>$reserved,'operational_reserved'=>$operationalReserved,'savings_reserved'=>$savingsReserved,
                'unallocated'=>$unallocated,'pending_total'=>$pendingTotal,'funded_pending'=>$fundedCoverage,
                'uncovered_pending'=>$uncoveredPending,'free_to_spend'=>$free,'income_change'=>$pct($income,$pi),
                'expense_change'=>$pct($expense,$pe),'balance_prev'=>$pi-$pe,
                'savings_rate'=>$income>0?($monthNet/$income)*100:0,'ant_projected'=>(float)$cur['ant'],
                'expected_fixed_income'=>0,'received_fixed_income'=>0,'pending_fixed_income'=>0
            ],
            'accounts'=>$accounts,'funds'=>$funds,'categories'=>$cat->fetchAll(),'daily'=>$daily->fetchAll(),
            'ant_expenses'=>$ant->fetchAll(),'pending'=>$pending,'pending_current'=>$pendingCurrent,
            'goals_current'=>[],'goals_next'=>[],'fixed_incomes'=>[],'recent'=>$recent->fetchAll(),
            'savings'=>$savingsSafe,'degraded_mode'=>true
        ];
        json_response(['ok'=>true,'data'=>$data,'warning'=>'Se usó el cálculo compatible del dashboard.']);
    } catch (Throwable $fallbackError) {
        error_log('[finanzas dashboard fallback] '.$fallbackError->getMessage().' in '.$fallbackError->getFile().':'.$fallbackError->getLine());
        json_response(['ok'=>false,'message'=>'No se pudo calcular el dashboard. Revisa el log de PHP del hosting.'],500);
    }
}
