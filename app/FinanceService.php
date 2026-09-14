<?php
class FinanceService {
    public static function ensureMonthlyPayments(int $userId, string $period): void {
        FinanceSchema::ensure($userId);
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
        [$y,$m] = array_map('intval', explode('-', $period));
        $days = cal_days_in_month(CAL_GREGORIAN, $m, $y);
        $periodStart=$period.'-01 00:00:00';
        $periodEnd=(new DateTimeImmutable($period.'-01'))->modify('+1 month')->format('Y-m-d H:i:s');

        $st = db()->prepare("SELECT id,amount,due_day,category_id,concept_id,name
            FROM recurring_payments
            WHERE user_id=? AND active=1 AND DATE_FORMAT(created_at,'%Y-%m')<=?");
        $st->execute([$userId,$period]);

        $paidRow=db()->prepare("SELECT id,paid_at,transaction_id FROM monthly_payments
            WHERE user_id=? AND recurring_id=? AND period=? AND status='paid'
            ORDER BY id DESC LIMIT 1");
        $pendingCount=db()->prepare("SELECT COUNT(*) FROM monthly_payments
            WHERE user_id=? AND recurring_id=? AND period=? AND status='pending'");
        $syncPaidDuplicates=db()->prepare("UPDATE monthly_payments
            SET status='paid',paid_at=?,transaction_id=?
            WHERE user_id=? AND recurring_id=? AND period=? AND status='pending'");
        $updatePending=db()->prepare("UPDATE monthly_payments
            SET due_date=?,amount=?
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

        foreach ($st->fetchAll() as $r) {
            $day=max(1,min((int)$r['due_day'],$days));
            $due=sprintf('%04d-%02d-%02d',$y,$m,$day);
            $rid=(int)$r['id'];

            // Si existe una fila pagada del mismo compromiso/mes, cualquier duplicado
            // pendiente es histórico y debe quedar cerrado, nunca recrearse.
            $paidRow->execute([$userId,$rid,$period]);
            $paid=$paidRow->fetch();
            if($paid){
                $syncPaidDuplicates->execute([$paid['paid_at'],$paid['transaction_id'],$userId,$rid,$period]);
                continue;
            }

            $pendingCount->execute([$userId,$rid,$period]);
            $hasPending=(int)$pendingCount->fetchColumn()>0;

            // Reparación defensiva: payment_mark crea transacciones con esta descripción
            // exacta. Si por una versión anterior el egreso se creó pero monthly_payments
            // no quedó en paid, conciliamos la obligación con esa transacción.
            // Conciliación defensiva. Primero buscamos por concepto/categoría/período.
            // La descripción exacta recibe prioridad, pero no es obligatoria porque
            // versiones anteriores podían haber creado el egreso con descripción vacía.
            $findPaymentTx->execute([
                $userId,(int)$r['category_id'],(int)$r['concept_id'],
                $periodStart,$periodEnd,'Pago mensual: '.$r['name']
            ]);
            $tx=$findPaymentTx->fetch();

            if($hasPending){
                if($tx){
                    $syncPaidDuplicates->execute([$tx['occurred_at'],(int)$tx['id'],$userId,$rid,$period]);
                    continue;
                }
                $updatePending->execute([$due,(float)$r['amount'],$userId,$rid,$period]);
                continue;
            }

            // Si la fila mensual desapareció pero el egreso ya existe, reconstruimos
            // directamente la obligación como pagada para no revivirla como pendiente.
            if($tx){
                $insertPaid->execute([$userId,$rid,$period,$due,(float)$r['amount'],$tx['occurred_at'],(int)$tx['id']]);
                continue;
            }

            $insert->execute([$userId,$rid,$period,$due,(float)$r['amount']]);
        }
    }

    public static function ensureMonthlyIncomes(int $userId, string $period): void {
        FinanceSchema::ensure($userId);
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
        if (!self::tableExistsForService('recurring_incomes') || !self::tableExistsForService('monthly_income_expectations')) return;
        $st = db()->prepare("SELECT id,amount,income_day FROM recurring_incomes WHERE user_id=? AND active=1 AND DATE_FORMAT(created_at,'%Y-%m')<=?");
        $st->execute([$userId,$period]);
        $ins = db()->prepare("INSERT INTO monthly_income_expectations(user_id,recurring_id,period,due_date,amount,created_at)
            VALUES(?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE due_date=VALUES(due_date), amount=VALUES(amount)");
        [$y,$m] = array_map('intval', explode('-', $period));
        $days = cal_days_in_month(CAL_GREGORIAN, $m, $y);
        foreach ($st->fetchAll() as $r) {
            $day = max(1, min((int)$r['income_day'], $days));
            $due = sprintf('%04d-%02d-%02d', $y,$m,$day);
            $ins->execute([$userId,$r['id'],$period,$due,$r['amount']]);
        }
    }

    private static function tableExistsForService(string $table): bool {
        $st=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }

    public static function accountBalances(int $userId): array {
        FinanceSchema::ensure($userId);
        $adjustmentSql = self::tableExistsForService('account_adjustments')
            ? "+ COALESCE((SELECT SUM(ad.amount) FROM account_adjustments ad WHERE ad.user_id=a.user_id AND ad.account_id=a.id),0)"
            : "";
        $sql = "SELECT a.id,a.name,a.account_type,a.icon,a.color,a.opening_balance,
            a.opening_balance
            + COALESCE((SELECT SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END) FROM transactions t WHERE t.user_id=a.user_id AND t.account_id=a.id),0)
            {$adjustmentSql}
            + COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.to_account_id=a.id),0)
            - COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr WHERE tr.user_id=a.user_id AND tr.from_account_id=a.id),0) balance
            FROM financial_accounts a WHERE a.user_id=? AND a.active=1 ORDER BY a.id";
        $st = db()->prepare($sql);
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public static function funds(int $userId): array {
        FinanceSchema::ensure($userId);
        $st = db()->prepare("SELECT f.id,f.name,f.icon,f.color,f.target_amount,
            CASE WHEN LOWER(TRIM(f.name))='ahorro' OR f.name LIKE '__SAV7__%' THEN -1 ELSE NULL END savings_goal_id,
            CASE WHEN LOWER(TRIM(f.name))='ahorro' OR f.name LIKE '__SAV7__%' THEN 'Ahorro' ELSE NULL END savings_goal_name,
            COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=f.user_id AND fa.fund_id=f.id),0) allocated_net,
            COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense'),0) spent,
            COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=f.user_id AND fa.fund_id=f.id),0)
             - COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense'),0) available
            FROM funds f WHERE f.user_id=? AND f.active=1 ORDER BY f.id");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public static function savingsGoalSaved(int $userId,int $goalId): float {
        $fund=SavingsSchema::savingsFund($userId,false,true);
        if(!$fund) return 0.0;
        $st=db()->prepare("SELECT amount,note FROM fund_allocations WHERE user_id=? AND fund_id=? ORDER BY id");
        $st->execute([$userId,(int)$fund['id']]);
        $sum=0.0;
        foreach($st->fetchAll() as $r){
            $m=SavingsSchema::parseMarker($r['note']??'');
            if(!$m['tracked'] || (int)$m['goal_id']!==$goalId) continue;
            $sum+=(float)$r['amount'];
        }
        return max(0,$sum);
    }

    public static function savingsOverview(int $userId): array {
        FinanceSchema::ensure($userId);
        SavingsSchema::ensure($userId);
        $fund=SavingsSchema::savingsFund($userId,false,true);

        $st=db()->prepare("SELECT id,name,period,target_amount FROM goals
            WHERE user_id=? AND type='savings'
            ORDER BY CASE WHEN period>=DATE_FORMAT(CURDATE(),'%Y-%m') THEN 0 ELSE 1 END,period,id");
        $st->execute([$userId]);

        $goals=[];$assignedToGoals=0.0;$totalTarget=0.0;
        foreach($st->fetchAll() as $g){
            $gid=(int)$g['id'];
            $saved=self::savingsGoalSaved($userId,$gid);
            $target=max(0,(float)$g['target_amount']);
            $g['fund_id']=$fund?(int)$fund['id']:0;
            $g['fund_icon']=$fund?($fund['icon']?:'💰'):'💰';
            $g['fund_color']=$fund?($fund['color']?:'#15803d'):'#15803d';
            $g['saved_amount']=$saved;
            $g['remaining_amount']=max(0,$target-$saved);
            $g['progress']=$target>0?min(100,($saved/$target)*100):0;
            $g['account_id']=null;$g['account_name']=null;
            $g['account_breakdown']=$fund?self::savingsAccountBreakdown($userId,$gid,(int)$fund['id'],$saved):[];
            if(!empty($g['account_breakdown'][0]['id'])){
                $g['account_id']=$g['account_breakdown'][0]['id'];
                $g['account_name']=$g['account_breakdown'][0]['name'];
            }
            $goals[]=$g;$assignedToGoals+=$saved;$totalTarget+=$target;
        }
        $totalSaved=$fund?max(0,self::fundAvailable($userId,(int)$fund['id'])):0.0;
        $generalSaved=max(0,self::savingsGoalSaved($userId,0));
        // Aportes antiguos sin marcador se consideran ahorro protegido general.
        $unassigned=max(0,$totalSaved-$assignedToGoals);
        $accountMap=self::savingsReservedByAccount($userId,null);
        $accounts=[];
        if($accountMap){
            $ids=array_keys($accountMap);$ph=implode(',',array_fill(0,count($ids),'?'));
            $q=db()->prepare("SELECT id,name,icon FROM financial_accounts WHERE user_id=? AND id IN ($ph)");
            $q->execute(array_merge([$userId],$ids));$meta=[];foreach($q->fetchAll() as $a)$meta[(int)$a['id']]=$a;
            foreach($accountMap as $aid=>$amount) if(!empty($meta[$aid])) $accounts[]=['id'=>$aid,'name'=>$meta[$aid]['name'],'icon'=>$meta[$aid]['icon'],'amount'=>$amount];
            usort($accounts,fn($a,$b)=>$b['amount']<=>$a['amount']);
        }
        $generalBreakdown=$fund?self::savingsAccountBreakdown($userId,0,(int)$fund['id'],$generalSaved):[];
        return ['goals'=>$goals,'total_saved'=>$totalSaved,'assigned_to_goals'=>$assignedToGoals,
            'general_saved'=>$generalSaved,'general_account_breakdown'=>$generalBreakdown,'unassigned_saved'=>$unassigned,'account_breakdown'=>$accounts,
            'total_target'=>$totalTarget,'remaining'=>max(0,$totalTarget-$assignedToGoals),
            'progress'=>$totalTarget>0?min(100,($assignedToGoals/$totalTarget)*100):0,'available'=>true];
    }

    public static function savingsAccountBreakdown(int $userId,int $goalId,int $fundId,float $savedTotal): array {
        $st=db()->prepare("SELECT id,amount,note,occurred_at FROM fund_allocations
            WHERE user_id=? AND fund_id=? ORDER BY occurred_at,id");
        $st->execute([$userId,$fundId]);
        $byAccount=[];
        foreach($st->fetchAll() as $row){
            $amount=(float)$row['amount'];
            $m=SavingsSchema::parseMarker($row['note']??'');
            if(!$m['tracked'] || (int)$m['goal_id']!==$goalId) continue;
            $aid=$amount>=0?(int)$m['to_account_id']:(int)$m['from_account_id'];
            if($aid)$byAccount[$aid]=($byAccount[$aid]??0)+$amount;
        }
        $rows=[];
        if($byAccount){
            $ids=array_keys($byAccount);$ph=implode(',',array_fill(0,count($ids),'?'));
            $q=db()->prepare("SELECT id,name,icon FROM financial_accounts WHERE user_id=? AND id IN ($ph)");
            $q->execute(array_merge([$userId],$ids));
            $map=[];foreach($q->fetchAll() as $a)$map[(int)$a['id']]=$a;
            foreach($byAccount as $id=>$amount){
                if($amount<=0.005 || empty($map[$id]))continue;
                $rows[]=['id'=>$id,'name'=>$map[$id]['name'],'icon'=>$map[$id]['icon'],'amount'=>$amount];
            }
            usort($rows,function($a,$b){return $b['amount']<=>$a['amount'];});
        }
        $known=0.0;foreach($rows as $r)$known+=(float)$r['amount'];
        $unknown=max(0,$savedTotal-$known);
        if($unknown>0.005)$rows[]=['id'=>null,'name'=>'Ahorro previo sin cuenta trazada','icon'=>'💰','amount'=>$unknown];
        return $rows;
    }

    public static function savingsHistory(int $userId, int $limit=30, ?string $start=null, ?string $end=null): array {
        FinanceSchema::ensure($userId);SavingsSchema::ensure($userId);
        $limit=max(1,min(500,$limit));$fund=SavingsSchema::savingsFund($userId,false,true);
        if(!$fund)return [];
        $where='WHERE fa.user_id=? AND fa.fund_id=?';
        $params=[$userId,(int)$fund['id']];
        if($start!==null){$where.=' AND fa.occurred_at>=?';$params[]=$start;}
        if($end!==null){$where.=' AND fa.occurred_at<?';$params[]=$end;}
        $st=db()->prepare("SELECT fa.id,fa.amount,fa.occurred_at,fa.note,u.name actor_name FROM fund_allocations fa LEFT JOIN users u ON u.id=COALESCE(fa.created_by_user_id,fa.user_id) {$where} ORDER BY fa.occurred_at DESC,fa.id DESC LIMIT {$limit}");
        $st->execute($params);
        $goalNames=[];$gs=db()->prepare("SELECT id,name FROM goals WHERE user_id=? AND type='savings'");$gs->execute([$userId]);
        foreach($gs->fetchAll() as $g)$goalNames[(int)$g['id']]=$g['name'];
        $accounts=[];foreach(self::accountBalances($userId) as $a)$accounts[(int)$a['id']]=$a;
        $out=[];
        foreach($st->fetchAll() as $r){
            $m=SavingsSchema::parseMarker($r['note']??'');if(!$m['tracked'])continue;
            $gid=(int)$m['goal_id'];
            $goalName=$gid===0?'Ahorro general':($goalNames[$gid]??null);
            if($goalName===null)continue;
            $amount=(float)$r['amount'];$kind=$amount<0?'withdrawal':'deposit';
            $from=(int)$m['from_account_id'];$to=(int)$m['to_account_id'];
            $out[]=['id'=>$r['id'],'movement_type'=>$kind,'amount'=>abs($amount),'occurred_at'=>$r['occurred_at'],
                'note'=>$m['note'],'goal_name'=>$goalName,
                'from_account'=>$from&&!empty($accounts[$from])?$accounts[$from]['name']:'—',
                'to_account'=>$to&&!empty($accounts[$to])?$accounts[$to]['name']:'—','actor_name'=>$r['actor_name']??'Usuario'];
        }
        return $out;
    }

    public static function totalCash(int $userId): float {
        $sum = 0.0;
        foreach (self::accountBalances($userId) as $a) $sum += (float)$a['balance'];
        return $sum;
    }

    public static function totalReserved(int $userId): float {
        $sum = 0.0;
        foreach (self::funds($userId) as $f) $sum += max(0, (float)$f['available']);
        return $sum;
    }

    public static function fundAvailable(int $userId, int $fundId): float {
        foreach (self::funds($userId) as $f) if ((int)$f['id'] === $fundId) return (float)$f['available'];
        return 0.0;
    }

    public static function accountBalance(int $userId, int $accountId): float {
        foreach (self::accountBalances($userId) as $a) if ((int)$a['id'] === $accountId) return (float)$a['balance'];
        return 0.0;
    }

    /**
     * Dinero de Ahorro protegido por cuenta física.
     * Solo considera movimientos de ahorro trazados (SAV7/SAV8/SAV9).
     * Un depósito protege dinero en la cuenta destino; un retiro lo libera
     * desde la cuenta origen.
     *
     * @return array<int,float> account_id => protected amount
     */
    public static function savingsReservedByAccount(int $userId, ?int $goalId=null): array {
        try {
            $fund=SavingsSchema::savingsFund($userId,false,true);
            if(!$fund) return [];
            $st=db()->prepare('SELECT amount,note FROM fund_allocations WHERE user_id=? AND fund_id=? ORDER BY id');
            $st->execute([$userId,(int)$fund['id']]);
            $out=[];
            foreach($st->fetchAll() as $r){
                $m=SavingsSchema::parseMarker($r['note']??'');
                if(!$m['tracked']) continue;
                if($goalId!==null && (int)$m['goal_id']!==$goalId) continue;
                $amount=(float)$r['amount'];
                $aid=$amount>=0 ? (int)$m['to_account_id'] : (int)$m['from_account_id'];
                if($aid<=0) continue;
                $out[$aid]=($out[$aid]??0.0)+$amount;
            }
            foreach($out as $aid=>$amount){
                $out[$aid]=max(0.0,round((float)$amount,2));
                if($out[$aid]<0.005) unset($out[$aid]);
            }
            return $out;
        } catch(Throwable $e) {
            error_log('[MiDinero savings reserved by account] '.$e->getMessage());
            return [];
        }
    }

    public static function accountSavingsReserved(int $userId,int $accountId): float {
        $map=self::savingsReservedByAccount($userId,null);
        return max(0.0,(float)($map[$accountId]??0));
    }

    /** Saldo que sí puede gastarse o transferirse por los flujos normales. */
    public static function accountSpendableBalance(int $userId,int $accountId): float {
        return max(0.0,self::accountBalance($userId,$accountId)-self::accountSavingsReserved($userId,$accountId));
    }

    /**
     * Dinero libre global después de fondos y ahorro ya reservados.
     * Los pagos pendientes son solo compromisos informativos: no reducen el
     * saldo hasta que se registran realmente como gasto/pago.
     */
    public static function freeToSpendNow(int $userId): float {
        return self::totalCash($userId)-self::totalReserved($userId);
    }

    public static function dashboard(int $userId, string $period): array {
        FinanceSchema::ensure($userId);
        self::ensureMonthlyPayments($userId, $period);
        self::ensureMonthlyIncomes($userId, $period);
        [$start,$end] = month_range($period);
        $startDt = new DateTimeImmutable($start);
        $prevPeriod = $startDt->modify('-1 month')->format('Y-m');
        [$pStart,$pEnd] = month_range($prevPeriod);

        $sumStmt = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income,
            COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense,
            COALESCE(SUM(CASE WHEN type='expense' AND is_ant_expense=1 THEN amount ELSE 0 END),0) ant
            FROM transactions WHERE user_id=? AND occurred_at>=? AND occurred_at<?");
        $sumStmt->execute([$userId,$start,$end]); $cur = $sumStmt->fetch();
        $sumStmt->execute([$userId,$pStart,$pEnd]); $prev = $sumStmt->fetch();

        $hasAdjustments = self::tableExistsForService('account_adjustments');
        if ($hasAdjustments) {
            $openingStmt = db()->prepare("SELECT
                COALESCE((SELECT SUM(opening_balance) FROM financial_accounts WHERE user_id=? AND active=1),0)
                + COALESCE((SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) FROM transactions WHERE user_id=? AND occurred_at<?),0)
                + COALESCE((SELECT SUM(amount) FROM account_adjustments WHERE user_id=? AND occurred_at<?),0)");
            $openingStmt->execute([$userId,$userId,$start,$userId,$start]);
            $adjStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM account_adjustments WHERE user_id=? AND occurred_at>=? AND occurred_at<?");
            $adjStmt->execute([$userId,$start,$end]);
            $monthAdjustment = (float)$adjStmt->fetchColumn();
        } else {
            $openingStmt = db()->prepare("SELECT
                COALESCE((SELECT SUM(opening_balance) FROM financial_accounts WHERE user_id=? AND active=1),0)
                + COALESCE((SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) FROM transactions WHERE user_id=? AND occurred_at<?),0)");
            $openingStmt->execute([$userId,$userId,$start]);
            $monthAdjustment = 0.0;
        }
        $opening = (float)$openingStmt->fetchColumn();

        $cat = db()->prepare("SELECT c.id,c.name,c.icon,c.color,SUM(t.amount) total
            FROM transactions t JOIN categories c ON c.id=t.category_id
            WHERE t.user_id=? AND t.type='expense' AND t.occurred_at>=? AND t.occurred_at<?
            GROUP BY c.id,c.name,c.icon,c.color ORDER BY total DESC LIMIT 8");
        $cat->execute([$userId,$start,$end]);

        if ($hasAdjustments) {
            $daily = db()->prepare("SELECT d,SUM(income) income,SUM(expense) expense,SUM(adjustment) adjustment FROM (
                SELECT DATE(occurred_at) d,
                    CASE WHEN type='income' THEN amount ELSE 0 END income,
                    CASE WHEN type='expense' THEN amount ELSE 0 END expense,
                    0 adjustment
                FROM transactions WHERE user_id=? AND occurred_at>=? AND occurred_at<?
                UNION ALL
                SELECT DATE(occurred_at) d,0 income,0 expense,amount adjustment
                FROM account_adjustments WHERE user_id=? AND occurred_at>=? AND occurred_at<?
            ) z GROUP BY d ORDER BY d");
            $daily->execute([$userId,$start,$end,$userId,$start,$end]);
        } else {
            $daily = db()->prepare("SELECT DATE(occurred_at) d,
                COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income,
                COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense,
                0 adjustment
                FROM transactions WHERE user_id=? AND occurred_at>=? AND occurred_at<?
                GROUP BY DATE(occurred_at) ORDER BY d");
            $daily->execute([$userId,$start,$end]);
        }

        $ant = db()->prepare("SELECT COALESCE(co.name,c.name) name,c.icon,SUM(t.amount) total,COUNT(*) qty
            FROM transactions t JOIN categories c ON c.id=t.category_id LEFT JOIN concepts co ON co.id=t.concept_id
            WHERE t.user_id=? AND t.type='expense' AND t.is_ant_expense=1 AND t.occurred_at>=? AND t.occurred_at<?
            GROUP BY name,c.icon ORDER BY total DESC LIMIT 6");
        $ant->execute([$userId,$start,$end]);

        $pending = db()->prepare("SELECT mp.id,mp.due_date,mp.amount,mp.status,r.name,r.icon,r.fund_id,DATEDIFF(mp.due_date,CURDATE()) days_left
            FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
            WHERE mp.user_id=? AND mp.period=? AND mp.status='pending' ORDER BY mp.due_date ASC");
        $pending->execute([$userId,$period]);
        $pendingRows = $pending->fetchAll();

        // El patrimonio y el dinero libre siempre se calculan con los compromisos del mes actual,
        // aunque el usuario esté revisando un mes histórico en el gráfico.
        $currentPeriod = date('Y-m');
        self::ensureMonthlyPayments($userId, $currentPeriod);

        // Para decidir cuánto dinero está realmente libre hay que considerar también
        // obligaciones vencidas de meses anteriores. Un pago impago no desaparece
        // porque cambió el mes.
        $currentMonthEnd = (new DateTimeImmutable($currentPeriod . '-01'))->modify('+1 month')->format('Y-m-d');
        $pendingNow = db()->prepare("SELECT mp.id,mp.period,mp.due_date,mp.amount,mp.status,r.name,r.icon,r.fund_id,DATEDIFF(mp.due_date,CURDATE()) days_left
            FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
            WHERE mp.user_id=? AND mp.status='pending' AND mp.due_date<?
            ORDER BY mp.due_date ASC");
        $pendingNow->execute([$userId,$currentMonthEnd]);
        $pendingFinancialRows = $pendingNow->fetchAll();

        $goals = db()->prepare('SELECT id,name,type,target_amount FROM goals WHERE user_id=? AND period=? ORDER BY id');
        $goals->execute([$userId,$period]); $gCur = $goals->fetchAll();
        $nextPeriod = $startDt->modify('+1 month')->format('Y-m');
        $goals->execute([$userId,$nextPeriod]); $gNext = $goals->fetchAll();

        $recent = db()->prepare("SELECT t.id,t.type,t.amount,t.occurred_at,t.description,t.is_ant_expense,c.name category,c.icon,co.name concept,a.name account,f.name fund
            FROM transactions t JOIN categories c ON c.id=t.category_id LEFT JOIN concepts co ON co.id=t.concept_id
            LEFT JOIN financial_accounts a ON a.id=t.account_id LEFT JOIN funds f ON f.id=t.fund_id
            WHERE t.user_id=? ORDER BY t.id DESC LIMIT 10");
        $recent->execute([$userId]);

        // Ingresos fijos son expectativas, no dinero real hasta que exista una
        // transacción. Derivamos cuánto se esperaba, cuánto llegó y cuánto falta.
        $fixedIncomeRows=[];
        if (class_exists('FinanceSchema')) {
            try {
                $fi=db()->prepare("SELECT r.id,r.name,r.icon,mie.amount expected_amount,DAY(mie.due_date) income_day,mie.due_date,r.concept_id,r.account_id,a.name account_name,
                    COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=r.user_id AND t.type='income' AND t.concept_id=r.concept_id AND t.occurred_at>=? AND t.occurred_at<?),0) received_amount
                    FROM monthly_income_expectations mie
                    JOIN recurring_incomes r ON r.id=mie.recurring_id AND r.user_id=mie.user_id
                    LEFT JOIN financial_accounts a ON a.id=r.account_id
                    WHERE mie.user_id=? AND mie.period=?
                    ORDER BY mie.due_date,r.id");
                $fi->execute([$start,$end,$userId,$period]);
                $fixedIncomeRows=$fi->fetchAll();
                foreach($fixedIncomeRows as &$fir){
                    $fir['expected_amount']=(float)$fir['expected_amount'];
                    $fir['received_amount']=(float)$fir['received_amount'];
                    $fir['remaining_amount']=max(0,$fir['expected_amount']-$fir['received_amount']);
                    $fir['status']=$fir['remaining_amount']<=0.005?'received':($fir['received_amount']>0?'partial':'pending');
                }
                unset($fir);
            } catch (Throwable $e) { $fixedIncomeRows=[]; }
        }

        $income=(float)$cur['income']; $expense=(float)$cur['expense']; $monthNet=$income-$expense;
        $pi=(float)$prev['income']; $pe=(float)$prev['expense'];
        $pct = function($a,$b) { return $b == 0.0 ? ($a == 0.0 ? 0 : 100) : (($a-$b)/abs($b))*100; };
        $closing = $opening + $monthNet + $monthAdjustment;

        $accounts = self::accountBalances($userId);
        $funds = self::funds($userId);
        $totalCash = array_sum(array_map(function($a){ return (float)$a['balance']; }, $accounts));
        $reserved=0.0;$operationalReserved=0.0;$savingsReserved=0.0;
        foreach($funds as $f){
            $av=max(0,(float)$f['available']);
            $reserved+=$av;
            if(!empty($f['savings_goal_id'])) $savingsReserved+=$av;
            else $operationalReserved+=$av;
        }
        $unallocated = $totalCash - $reserved;
        $pendingTotal = array_sum(array_map(function($r){ return (float)$r['amount']; }, $pendingFinancialRows));

        $fundAvailableMap=[];
        foreach($funds as $f) $fundAvailableMap[(int)$f['id']] = max(0,(float)$f['available']);
        $dueByFund=[];
        foreach($pendingFinancialRows as $p) if(!empty($p['fund_id'])) $dueByFund[(int)$p['fund_id']] = ($dueByFund[(int)$p['fund_id']] ?? 0) + (float)$p['amount'];
        $fundedCoverage=0;
        foreach($dueByFund as $fid=>$due) $fundedCoverage += min($due, $fundAvailableMap[$fid] ?? 0);
        $uncoveredPending=max(0,$pendingTotal-$fundedCoverage);
        // Los pendientes se muestran como referencia, pero no descuentan dinero real.
        // El saldo cambia recién cuando el usuario registra efectivamente el pago/gasto.
        $freeToSpend=$unallocated;

        $savings=self::savingsOverview($userId);
        $savingByGoal=[];foreach($savings['goals'] as $sg)$savingByGoal[(int)$sg['id']]=(float)$sg['saved_amount'];
        foreach ($gCur as &$g) {
            if ($g['type']==='savings') $g['progress_amount']=max(0,$savingByGoal[(int)$g['id']]??0);
            elseif ($g['type']==='income') $g['progress_amount']=$income;
            else $g['progress_amount']=$expense;
        }

        $expectedFixedIncome=array_sum(array_map(function($r){return (float)$r['expected_amount'];},$fixedIncomeRows));
        $receivedFixedIncome=array_sum(array_map(function($r){return min((float)$r['expected_amount'],(float)$r['received_amount']);},$fixedIncomeRows));
        $pendingFixedIncome=array_sum(array_map(function($r){return (float)$r['remaining_amount'];},$fixedIncomeRows));

        $daysInMonth=(int)$startDt->format('t');
        $dayNow = ($period===date('Y-m')) ? (int)date('j') : $daysInMonth;
        $antProjected = $dayNow>0 ? ((float)$cur['ant']/$dayNow)*$daysInMonth : 0;

        return [
            'period'=>$period,'previous_period'=>$prevPeriod,'next_period'=>$nextPeriod,
            'summary'=>[
                'income'=>$income,'expense'=>$expense,'balance'=>$monthNet,'adjustment'=>$monthAdjustment,'ant'=>(float)$cur['ant'],
                'opening_balance'=>$opening,'closing_balance'=>$closing,'total_cash'=>$totalCash,'available_in_accounts'=>$totalCash,
                'reserved'=>$reserved,'operational_reserved'=>$operationalReserved,'savings_reserved'=>$savingsReserved,
                'unallocated'=>$unallocated,'pending_total'=>$pendingTotal,
                'funded_pending'=>$fundedCoverage,'uncovered_pending'=>$uncoveredPending,'free_to_spend'=>$freeToSpend,
                'income_change'=>$pct($income,$pi),'expense_change'=>$pct($expense,$pe),'balance_prev'=>$pi-$pe,
                'savings_rate'=>$income>0 ? ($monthNet/$income)*100 : 0,'ant_projected'=>$antProjected,
                'expected_fixed_income'=>$expectedFixedIncome,'received_fixed_income'=>$receivedFixedIncome,'pending_fixed_income'=>$pendingFixedIncome
            ],
            'accounts'=>$accounts,'funds'=>$funds,'categories'=>$cat->fetchAll(),'daily'=>$daily->fetchAll(),'ant_expenses'=>$ant->fetchAll(),
            'pending'=>$pendingRows,'pending_current'=>$pendingFinancialRows,'current_period'=>$currentPeriod,
            'goals_current'=>$gCur,'goals_next'=>$gNext,'fixed_incomes'=>$fixedIncomeRows,'recent'=>$recent->fetchAll(),
            'savings'=>$savings
        ];
    }
}
