<?php
class MonthCloseService {
    private static function tableExists(string $table): bool {
        try {
            $st=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    private static function columnExists(string $table,string $column): bool {
        try {
            $st=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $st->execute([$table,$column]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    private static function notVoided(string $table,string $alias): string {
        return self::columnExists($table,'voided_at') ? " AND {$alias}.voided_at IS NULL" : '';
    }

    private static function accountsAt(int $userId,string $until): array {
        if(!self::tableExists('financial_accounts')) return ['rows'=>[],'total'=>0.0];

        $txExpr='0';
        if(self::tableExists('transactions') && self::columnExists('transactions','account_id')){
            $txVoid=self::notVoided('transactions','t');
            $txExpr="COALESCE((SELECT SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END)
                FROM transactions t WHERE t.user_id=a.user_id AND t.account_id=a.id{$txVoid} AND t.occurred_at<".db()->quote($until)."),0)";
        }

        $adjExpr='0';
        if(self::tableExists('account_adjustments')){
            $adjVoid=self::notVoided('account_adjustments','ad');
            $adjExpr="COALESCE((SELECT SUM(ad.amount) FROM account_adjustments ad
                WHERE ad.user_id=a.user_id AND ad.account_id=a.id{$adjVoid} AND ad.occurred_at<".db()->quote($until)."),0)";
        }

        $inExpr='0';$outExpr='0';
        if(self::tableExists('account_transfers')){
            $trVoid=self::notVoided('account_transfers','tr');
            $quoted=db()->quote($until);
            $inExpr="COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr
                WHERE tr.user_id=a.user_id AND tr.to_account_id=a.id{$trVoid} AND tr.occurred_at<{$quoted}),0)";
            $outExpr="COALESCE((SELECT SUM(tr.amount) FROM account_transfers tr
                WHERE tr.user_id=a.user_id AND tr.from_account_id=a.id{$trVoid} AND tr.occurred_at<{$quoted}),0)";
        }

        $active=self::columnExists('financial_accounts','active') ? ' AND a.active=1' : '';
        $opening=self::columnExists('financial_accounts','opening_balance') ? 'a.opening_balance' : '0';
        $sql="SELECT a.id,a.name,({$opening}+{$txExpr}+{$adjExpr}+{$inExpr}-{$outExpr}) balance
              FROM financial_accounts a WHERE a.user_id=?{$active} ORDER BY a.id";
        $st=db()->prepare($sql);$st->execute([$userId]);
        $rows=[];$total=0.0;
        foreach($st->fetchAll() as $a){
            $balance=round((float)($a['balance']??0),2);$total+=$balance;
            $rows[]=['id'=>(int)($a['id']??0),'name'=>(string)($a['name']??'Cuenta'),'balance'=>$balance];
        }
        return ['rows'=>$rows,'total'=>round($total,2)];
    }

    private static function transactionSummary(int $userId,string $start,string $end): array {
        $income=0.0;$expense=0.0;$adjustment=0.0;
        if(self::tableExists('transactions')){
            $void=self::notVoided('transactions','t');
            $st=db()->prepare("SELECT
                COALESCE(SUM(CASE WHEN t.type='income' THEN t.amount ELSE 0 END),0) income,
                COALESCE(SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END),0) expense
                FROM transactions t WHERE t.user_id=?{$void} AND t.occurred_at>=? AND t.occurred_at<?");
            $st->execute([$userId,$start,$end]);$r=$st->fetch()?:[];
            $income=(float)($r['income']??0);$expense=(float)($r['expense']??0);
        }
        if(self::tableExists('account_adjustments')){
            $void=self::notVoided('account_adjustments','ad');
            $st=db()->prepare("SELECT COALESCE(SUM(ad.amount),0) FROM account_adjustments ad
                WHERE ad.user_id=?{$void} AND ad.occurred_at>=? AND ad.occurred_at<?");
            $st->execute([$userId,$start,$end]);$adjustment=(float)$st->fetchColumn();
        }
        return [
            'income'=>round($income,2),
            'expense'=>round($expense,2),
            'balance'=>round($income-$expense,2),
            'adjustment'=>round($adjustment,2),
        ];
    }

    private static function fundsAt(int $userId,string $until): array {
        if(!self::tableExists('funds')) return ['rows'=>[],'savings'=>0.0,'operational'=>0.0];
        $allocExpr='0';
        if(self::tableExists('fund_allocations')){
            $void=self::notVoided('fund_allocations','fa');
            $allocExpr="COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa
                WHERE fa.user_id=f.user_id AND fa.fund_id=f.id{$void} AND fa.occurred_at<".db()->quote($until)."),0)";
        }
        $spentExpr='0';
        if(self::tableExists('transactions') && self::columnExists('transactions','fund_id')){
            $void=self::notVoided('transactions','t');
            $spentExpr="COALESCE((SELECT SUM(t.amount) FROM transactions t
                WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense'{$void} AND t.occurred_at<".db()->quote($until)."),0)";
        }
        $active=self::columnExists('funds','active')?' AND f.active=1':'';
        $st=db()->prepare("SELECT f.id,f.name,({$allocExpr}-{$spentExpr}) available FROM funds f WHERE f.user_id=?{$active} ORDER BY f.id");
        $st->execute([$userId]);
        $rows=[];$savings=0.0;$operational=0.0;
        foreach($st->fetchAll() as $f){
            $available=round(max(0,(float)($f['available']??0)),2);
            $name=(string)($f['name']??'Fondo');
            $rows[]=['id'=>(int)($f['id']??0),'name'=>$name,'available'=>$available];
            $norm=function_exists('mb_strtolower')?mb_strtolower(trim($name)):strtolower(trim($name));
            if($norm==='ahorro' || strpos($name,'__SAV7__')===0) $savings+=$available; else $operational+=$available;
        }
        return ['rows'=>$rows,'savings'=>round($savings,2),'operational'=>round($operational,2)];
    }

    private static function paymentsForPeriod(int $userId,string $period): array {
        $rows=[];$pending=0.0;
        if(self::tableExists('monthly_payments') && self::tableExists('recurring_payments')){
            $hasPaid=self::columnExists('monthly_payments','paid_amount');
            $paidExpr=$hasPaid?'mp.paid_amount':"CASE WHEN mp.status='paid' THEN mp.amount ELSE 0 END";
            $st=db()->prepare("SELECT mp.id,mp.amount,{$paidExpr} paid_amount,
                    GREATEST(mp.amount-({$paidExpr}),0) remaining_amount,mp.status,mp.due_date,r.name
                FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
                WHERE mp.user_id=? AND mp.period=? ORDER BY mp.due_date,r.id");
            $st->execute([$userId,$period]);
            foreach($st->fetchAll() as $p){
                $status=(string)($p['status']??'pending');
                $remaining=round((float)($p['remaining_amount']??0),2);
                if(in_array($status,['pending','partial'],true))$pending+=$remaining;
                $rows[]=[
                    'id'=>(int)($p['id']??0),'name'=>(string)($p['name']??'Pago fijo'),
                    'amount'=>round((float)($p['amount']??0),2),'paid_amount'=>round((float)($p['paid_amount']??0),2),
                    'remaining_amount'=>$remaining,'status'=>$status,'due_date'=>(string)($p['due_date']??($period.'-01'))
                ];
            }
        }

        // Si aún no se materializaron los pagos del mes, el cierre sigue mostrando
        // los compromisos fijos activos sin escribir nada en la base de datos.
        if(!$rows && self::tableExists('recurring_payments')){
            [$y,$m]=array_map('intval',explode('-',$period));
            $days=cal_days_in_month(CAL_GREGORIAN,$m,$y);
            $createdFilter=self::columnExists('recurring_payments','created_at') ? " AND DATE_FORMAT(created_at,'%Y-%m')<=?" : '';
            $active=self::columnExists('recurring_payments','active')?' AND active=1':'';
            $sql="SELECT id,name,amount,due_day FROM recurring_payments WHERE user_id=?{$active}{$createdFilter} ORDER BY due_day,id";
            $st=db()->prepare($sql);$params=[$userId];if($createdFilter!=='')$params[]=$period;$st->execute($params);
            foreach($st->fetchAll() as $r){
                $day=max(1,min((int)($r['due_day']??1),$days));$amount=round((float)($r['amount']??0),2);
                $rows[]=['id'=>0,'name'=>(string)($r['name']??'Pago fijo'),'amount'=>$amount,'paid_amount'=>0.0,
                    'remaining_amount'=>$amount,'status'=>'pending','due_date'=>sprintf('%04d-%02d-%02d',$y,$m,$day)];
                $pending+=$amount;
            }
        }
        return ['rows'=>$rows,'pending'=>round($pending,2)];
    }

    public static function snapshot(int $userId,string $period): array {
        if(!preg_match('/^\d{4}-\d{2}$/',$period)) throw new DomainException('Mes no válido.');
        [$start,$end]=month_range($period);

        // Este módulo es deliberadamente de solo lectura. No ejecuta migraciones ni
        // genera pagos al abrirse; así una estructura parcial del hosting no bloquea
        // el cierre ni altera información por el simple hecho de consultar el mes.
        $summary=self::transactionSummary($userId,$start,$end);
        $opening=self::accountsAt($userId,$start);
        $closing=self::accountsAt($userId,$end);
        $funds=self::fundsAt($userId,$end);
        $payments=self::paymentsForPeriod($userId,$period);

        // Si una instalación antigua aún no tiene cuentas, mantenemos un total
        // coherente a partir de la actividad del mes en lugar de devolver ceros.
        $openingTotal=(float)$opening['total'];
        $closingTotal=(float)$closing['total'];
        if(!$closing['rows'] && !$opening['rows']){
            $closingTotal=round($openingTotal+$summary['balance']+$summary['adjustment'],2);
        }

        return [
            'period'=>$period,
            'captured_at'=>date('Y-m-d H:i:s'),
            'summary'=>[
                'income'=>$summary['income'],
                'expense'=>$summary['expense'],
                'balance'=>$summary['balance'],
                'adjustment'=>$summary['adjustment'],
                'opening_balance'=>round($openingTotal,2),
                'closing_balance'=>round($closingTotal,2),
                'available_in_accounts'=>round($closingTotal,2),
                'pending_total'=>$payments['pending'],
                'after_commitments'=>round($closingTotal-$payments['pending'],2),
                'savings_reserved'=>$funds['savings'],
                'operational_reserved'=>$funds['operational'],
            ],
            'accounts'=>$closing['rows'],
            'funds'=>$funds['rows'],
            'payments'=>$payments['rows']
        ];
    }

    public static function hash(array $snapshot): string {
        $copy=$snapshot;unset($copy['captured_at']);
        return hash('sha256',json_encode($copy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function ensureStorage(): void {
        $pdo=db();
        if(!self::tableExists('monthly_closures')){
            $pdo->exec("CREATE TABLE monthly_closures (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                period CHAR(7) NOT NULL,
                snapshot_json LONGTEXT NOT NULL,
                snapshot_hash CHAR(64) NOT NULL DEFAULT '',
                notes VARCHAR(500) DEFAULT NULL,
                closed_by_user_id INT UNSIGNED DEFAULT NULL,
                closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reopened_at DATETIME DEFAULT NULL,
                reopened_by_user_id INT UNSIGNED DEFAULT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uq_monthly_closure(user_id,period),
                KEY idx_monthly_closure_date(user_id,closed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }
        $defs=[
            'snapshot_json'=>'LONGTEXT NULL',
            'snapshot_hash'=>"CHAR(64) NOT NULL DEFAULT ''",
            'notes'=>'VARCHAR(500) DEFAULT NULL',
            'closed_by_user_id'=>'INT UNSIGNED DEFAULT NULL',
            'closed_at'=>'DATETIME NULL',
            'reopened_at'=>'DATETIME DEFAULT NULL',
            'reopened_by_user_id'=>'INT UNSIGNED DEFAULT NULL',
        ];
        foreach($defs as $col=>$def){
            if(!self::columnExists('monthly_closures',$col)){
                try{$pdo->exec("ALTER TABLE monthly_closures ADD COLUMN `{$col}` {$def}");}catch(Throwable $e){error_log('[MiDinero cierre storage] '.$e->getMessage());}
            }
        }
    }

    public static function get(int $userId,string $period): ?array {
        // Consultar el cierre nunca debe ejecutar DDL. Si todavía no existe la tabla,
        // simplemente significa que el mes aún no ha sido cerrado.
        if(!self::tableExists('monthly_closures')) return null;
        $st=db()->prepare('SELECT * FROM monthly_closures WHERE user_id=? AND period=? LIMIT 1');
        $st->execute([$userId,$period]);$r=$st->fetch();if(!$r)return null;
        $d=json_decode((string)($r['snapshot_json']??''),true);$r['snapshot']=is_array($d)?$d:[];
        $r['snapshot_hash']=(string)($r['snapshot_hash']??'');
        $r['is_closed']=empty($r['reopened_at']);
        $r['closed_by_name']='Usuario';$r['reopened_by_name']='Usuario';
        try{
            if(!empty($r['closed_by_user_id'])){$u=db()->prepare('SELECT name FROM users WHERE id=? LIMIT 1');$u->execute([(int)$r['closed_by_user_id']]);$r['closed_by_name']=(string)($u->fetchColumn()?:'Usuario');}
            if(!empty($r['reopened_by_user_id'])){$u=db()->prepare('SELECT name FROM users WHERE id=? LIMIT 1');$u->execute([(int)$r['reopened_by_user_id']]);$r['reopened_by_name']=(string)($u->fetchColumn()?:'Usuario');}
        }catch(Throwable $e){}
        return $r;
    }

    private static function auditSafe(int $userId,string $action,int $entityId,string $title,string $summary,array $snapshot=[]): void {
        try{
            FinanceAudit::record($userId,$action,'monthly_closure',$entityId,$title,$summary,null,$snapshot?:null,['period'=>$snapshot['period']??null],false);
        }catch(Throwable $e){error_log('[MiDinero cierre audit] '.$e->getMessage());}
    }

    public static function close(int $userId,string $period,string $notes=''): array {
        if(!preg_match('/^\d{4}-\d{2}$/',$period))throw new DomainException('Mes no válido.');
        if($period>date('Y-m'))throw new DomainException('No puedes cerrar un mes futuro.');
        self::ensureStorage();
        $pdo=db();$pdo->beginTransaction();
        try{
            finance_lock_user($userId);
            $cur=$pdo->prepare('SELECT * FROM monthly_closures WHERE user_id=? AND period=? FOR UPDATE');
            $cur->execute([$userId,$period]);$row=$cur->fetch();
            if($row && empty($row['reopened_at']))throw new DomainException('Este mes ya está cerrado.');
            $snapshot=self::snapshot($userId,$period);$hash=self::hash($snapshot);$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($row){
                $up=$pdo->prepare("UPDATE monthly_closures SET snapshot_json=?,snapshot_hash=?,notes=?,closed_by_user_id=?,closed_at=NOW(),reopened_at=NULL,reopened_by_user_id=NULL WHERE id=? AND user_id=?");
                $up->execute([$json,$hash,substr(trim($notes),0,500),actual_user_id()?:$userId,(int)$row['id'],$userId]);$id=(int)$row['id'];
            }else{
                $in=$pdo->prepare('INSERT INTO monthly_closures(user_id,period,snapshot_json,snapshot_hash,notes,closed_by_user_id,closed_at) VALUES(?,?,?,?,?,?,NOW())');
                $in->execute([$userId,$period,$json,$hash,substr(trim($notes),0,500),actual_user_id()?:$userId]);$id=(int)$pdo->lastInsertId();
            }
            $pdo->commit();
            self::auditSafe($userId,'month_closed',$id,'Mes cerrado','Se guardó una fotografía financiera de '.$period,$snapshot);
            try{emit_event($userId,'month_closed',['period'=>$period,'id'=>$id]);}catch(Throwable $e){}
            return self::get($userId,$period)??[];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function reopen(int $userId,string $period): void {
        if(!HouseholdSchema::canManage(actual_user_id()?:$userId))throw new DomainException('Solo un administrador del hogar puede reabrir un mes.');
        self::ensureStorage();
        $pdo=db();$pdo->beginTransaction();
        try{
            finance_lock_user($userId);
            $st=$pdo->prepare('SELECT id,reopened_at FROM monthly_closures WHERE user_id=? AND period=? FOR UPDATE');$st->execute([$userId,$period]);$row=$st->fetch();
            if(!$row || !empty($row['reopened_at']))throw new DomainException('El mes no está cerrado.');
            $pdo->prepare('UPDATE monthly_closures SET reopened_at=NOW(),reopened_by_user_id=? WHERE id=? AND user_id=?')->execute([actual_user_id()?:$userId,(int)$row['id'],$userId]);
            $pdo->commit();
            self::auditSafe($userId,'month_reopened',(int)$row['id'],'Mes reabierto','El cierre de '.$period.' fue reabierto',['period'=>$period]);
            try{emit_event($userId,'month_reopened',['period'=>$period]);}catch(Throwable $e){}
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
