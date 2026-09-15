<?php
class FinancialCalendarService {
    public static function month(int $userId,string $period): array {
        if(!preg_match('/^\d{4}-\d{2}$/',$period))$period=date('Y-m');
        FinanceSchema::ensure($userId);
        FinanceService::ensureMonthlyPayments($userId,$period);
        FinanceService::ensureMonthlyIncomes($userId,$period);
        [$start,$end]=month_range($period);

        $pay=db()->prepare("SELECT mp.id,mp.period,mp.due_date,mp.amount,mp.paid_amount,GREATEST(mp.amount-mp.paid_amount,0) remaining_amount,mp.status,
            r.name,r.icon,r.fund_id,c.name category_name,co.name concept_name
            FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id AND r.user_id=mp.user_id
            LEFT JOIN categories c ON c.id=r.category_id LEFT JOIN concepts co ON co.id=r.concept_id
            WHERE mp.user_id=? AND mp.period=? ORDER BY mp.due_date,mp.id");
        $pay->execute([$userId,$period]);$payments=$pay->fetchAll();

        $incomes=[];
        try{
            $inc=db()->prepare("SELECT mie.id,mie.due_date,mie.amount expected_amount,r.name,r.icon,r.concept_id,r.account_id,a.name account_name,
                COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=mie.user_id AND t.voided_at IS NULL AND t.type='income' AND t.concept_id=r.concept_id AND t.occurred_at>=? AND t.occurred_at<?),0) received_amount
                FROM monthly_income_expectations mie JOIN recurring_incomes r ON r.id=mie.recurring_id AND r.user_id=mie.user_id
                LEFT JOIN financial_accounts a ON a.id=r.account_id
                WHERE mie.user_id=? AND mie.period=? ORDER BY mie.due_date,mie.id");
            $inc->execute([$start,$end,$userId,$period]);$incomes=$inc->fetchAll();
            foreach($incomes as &$r){$r['remaining_amount']=max(0,(float)$r['expected_amount']-(float)$r['received_amount']);$r['status']=$r['remaining_amount']<=0.005?'received':((float)$r['received_amount']>0?'partial':'pending');}unset($r);
        }catch(Throwable $e){error_log('[MiDinero calendar incomes] '.$e->getMessage());}

        $accounts=FinanceService::accountBalances($userId);$protected=FinanceService::savingsReservedByAccount($userId,null);
        foreach($accounts as &$a){$a['savings_reserved']=max(0,(float)($protected[(int)$a['id']]??0));$a['spendable_balance']=max(0,(float)$a['balance']-$a['savings_reserved']);}unset($a);
        $funds=array_values(array_filter(FinanceService::funds($userId),fn($f)=>empty($f['savings_goal_id'])));

        return ['period'=>$period,'payments'=>$payments,'incomes'=>$incomes,'accounts'=>$accounts,'funds'=>$funds];
    }
}
