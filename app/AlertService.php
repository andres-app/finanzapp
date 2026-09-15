<?php
class AlertService {
    public static function current(int $userId): array {
        FinanceSchema::ensure($userId);
        $period=date('Y-m');
        FinanceService::ensureMonthlyPayments($userId,$period);
        $alerts=[];
        $today=new DateTimeImmutable('today');
        $in3=$today->modify('+3 days')->format('Y-m-d');
        $in7=$today->modify('+7 days')->format('Y-m-d');

        $due=db()->prepare("SELECT mp.id,mp.period,mp.due_date,mp.amount,mp.paid_amount,GREATEST(mp.amount-mp.paid_amount,0) remaining_amount,r.name,r.icon
            FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id AND r.user_id=mp.user_id
            WHERE mp.user_id=? AND mp.status IN ('pending','partial') AND mp.amount-mp.paid_amount>0.005
              AND mp.due_date<=? ORDER BY mp.due_date,mp.id");
        $due->execute([$userId,$in3]);
        foreach($due->fetchAll() as $r){
            $days=(int)$today->diff(new DateTimeImmutable($r['due_date']))->format('%r%a');
            if($days<0){$title=$r['name'].' está vencido';$body='Venció hace '.abs($days).' '.(abs($days)===1?'día':'días').' · pendiente S/ '.number_format((float)$r['remaining_amount'],2);$severity='danger';}
            elseif($days===0){$title=$r['name'].' vence hoy';$body='Pendiente S/ '.number_format((float)$r['remaining_amount'],2);$severity='warning';}
            else {$title=$r['name'].' vence en '.$days.' '.($days===1?'día':'días');$body='Pendiente S/ '.number_format((float)$r['remaining_amount'],2);$severity='warning';}
            $alerts[]=['id'=>'payment-'.$r['id'].'-'.$r['due_date'],'kind'=>'payment','severity'=>$severity,'icon'=>$r['icon']?:'🧾','title'=>$title,'body'=>$body,'href'=>app_url('calendario?period='.$r['period'].'&pay='.$r['id']),'sort'=>$days<0?5:20+$days];
        }

        $week=db()->prepare("SELECT COALESCE(SUM(GREATEST(mp.amount-mp.paid_amount,0)),0) total,COUNT(*) qty
            FROM monthly_payments mp WHERE mp.user_id=? AND mp.status IN ('pending','partial') AND mp.amount-mp.paid_amount>0.005
              AND mp.due_date>=? AND mp.due_date<=?");
        $week->execute([$userId,$today->format('Y-m-d'),$in7]);
        $w=$week->fetch();
        if((float)$w['total']>0.005){
            $alerts[]=['id'=>'week-'.$today->format('o-W'),'kind'=>'week','severity'=>'info','icon'=>'📅','title'=>'Tienes S/ '.number_format((float)$w['total'],2).' pendientes esta semana','body'=>(int)$w['qty'].' '.((int)$w['qty']===1?'compromiso vence':'compromisos vencen').' en los próximos 7 días.','href'=>app_url('calendario?period='.$period),'sort'=>40];
        }

        [$cs,$ce]=month_range($period);
        $prev=(new DateTimeImmutable($period.'-01'))->modify('-1 month')->format('Y-m');
        [$ps,$pe]=month_range($prev);
        $cmp=db()->prepare("SELECT c.id,c.name,c.icon,
            COALESCE(SUM(CASE WHEN t.occurred_at>=? AND t.occurred_at<? THEN t.amount ELSE 0 END),0) current_total,
            COALESCE(SUM(CASE WHEN t.occurred_at>=? AND t.occurred_at<? THEN t.amount ELSE 0 END),0) previous_total
            FROM categories c LEFT JOIN transactions t ON t.category_id=c.id AND t.user_id=c.user_id AND t.type='expense' AND t.voided_at IS NULL
              AND t.occurred_at>=? AND t.occurred_at<?
            WHERE c.user_id=? AND c.active=1
            GROUP BY c.id,c.name,c.icon
            HAVING current_total>previous_total AND current_total-previous_total>=10
            ORDER BY (current_total-previous_total) DESC LIMIT 2");
        $cmp->execute([$cs,$ce,$ps,$pe,$ps,$ce,$userId]);
        foreach($cmp->fetchAll() as $r){
            $cur=(float)$r['current_total'];$prevTotal=(float)$r['previous_total'];$delta=$cur-$prevTotal;
            $pct=$prevTotal>0?round(($delta/$prevTotal)*100):null;
            $body=$prevTotal>0?'Llevas S/ '.number_format($cur,2).', '.$pct.'% más que el mes pasado.':'Llevas S/ '.number_format($cur,2).' y el mes pasado no registraste gasto en esta categoría.';
            $alerts[]=['id'=>'category-'.$r['id'].'-'.$period,'kind'=>'spending','severity'=>'info','icon'=>$r['icon']?:'📈','title'=>$r['name'].' superó el gasto del mes pasado','body'=>$body,'href'=>app_url('dashboard?period='.$period),'sort'=>60];
        }

        try{
            $dash=FinanceService::dashboard($userId,$period);
            $after=(float)($dash['summary']['after_commitments']??0);
            $cash=(float)($dash['summary']['available_in_accounts']??0);
            $pending=(float)($dash['summary']['pending_total']??0);
            if($pending>0.005 && $after<1000){
                $body='Disponible hoy S/ '.number_format($cash,2).' · compromisos S/ '.number_format($pending,2).' · quedarían S/ '.number_format($after,2).'.';
                $alerts[]=['id'=>'low-after-'.$period,'kind'=>'projection','severity'=>$after<0?'danger':'warning','icon'=>'⚠️','title'=>$after<0?'Tus compromisos superan tu saldo actual':'Tu saldo bajará de S/ 1,000 si pagas todo lo pendiente','body'=>$body,'href'=>app_url('planificador'),'sort'=>10];
            }
        }catch(Throwable $e){error_log('[MiDinero alerts projection] '.$e->getMessage());}

        usort($alerts,fn($a,$b)=>($a['sort']??99)<=>($b['sort']??99));
        foreach($alerts as &$a) unset($a['sort']); unset($a);
        return $alerts;
    }
}
