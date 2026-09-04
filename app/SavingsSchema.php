<?php
/**
 * SavingsSchema v8
 *
 * Modelo simple y estable, sin migraciones:
 * - goals = para qué se ahorra
 * - un único fondo existente "Ahorro" = dinero reservado
 * - fund_allocations.note = meta + cuenta física (trazabilidad)
 * - account_transfers = movimiento físico entre cuentas cuando corresponde
 */
class SavingsSchema {
    public static function ensure(int $userId): void {
        // No CREATE TABLE, no ALTER TABLE, no fondos ocultos por meta.
        // Solo garantizamos que exista el fondo operativo Ahorro.
        self::savingsFund($userId, false, true);
    }

    /** @return array|null */
    public static function savingsFund(int $userId, bool $forUpdate=false, bool $createIfMissing=true): ?array {
        $suffix=$forUpdate?' FOR UPDATE':'';
        $st=db()->prepare("SELECT id,user_id,name,icon,color,target_amount,active FROM funds
            WHERE user_id=? AND active=1 AND LOWER(TRIM(name))='ahorro'
            ORDER BY id LIMIT 1{$suffix}");
        $st->execute([$userId]);
        $row=$st->fetch();
        if($row) return $row;
        if(!$createIfMissing) return null;

        // Crear un fondo normal no es una migración de esquema y mantiene compatibilidad.
        $ins=db()->prepare("INSERT INTO funds(user_id,name,icon,color,target_amount,active) VALUES(?,'Ahorro','💰','#15803d',0,1)");
        $ins->execute([$userId]);
        $id=(int)db()->lastInsertId();
        return ['id'=>$id,'user_id'=>$userId,'name'=>'Ahorro','icon'=>'💰','color'=>'#15803d','target_amount'=>0,'active'=>1];
    }

    public static function linkForGoal(int $userId,int $goalId,bool $forUpdate=false): ?array {
        self::ensure($userId);
        $suffix=$forUpdate?' FOR UPDATE':'';
        $st=db()->prepare("SELECT id,user_id,name,target_amount,period,type FROM goals
            WHERE user_id=? AND id=? AND type='savings' LIMIT 1{$suffix}");
        $st->execute([$userId,$goalId]);
        $g=$st->fetch();
        if(!$g) return null;
        $f=self::savingsFund($userId,$forUpdate,true);
        if(!$f) return null;
        $g['fund_id']=(int)$f['id'];
        $g['fund_icon']=$f['icon']?:'💰';
        $g['fund_color']=$f['color']?:'#15803d';
        $g['preferred_account_id']=null;
        return $g;
    }

    public static function setPreferredAccount(int $userId,int $goalId,?int $accountId): void {
        // V8 no necesita una cuenta preferida persistente.
    }

    public static function isSavingsFund(int $userId,int $fundId): bool {
        if($fundId<=0) return false;
        $st=db()->prepare("SELECT COUNT(*) FROM funds WHERE user_id=? AND id=? AND active=1
            AND (LOWER(TRIM(name))='ahorro' OR name LIKE '__SAV7__%')");
        $st->execute([$userId,$fundId]);
        return (bool)$st->fetchColumn();
    }

    public static function marker(string $kind,int $goalId,int $fromAccount,int $toAccount,string $note=''): string {
        $kind=$kind==='withdrawal'?'out':'in';
        $safe=trim((string)preg_replace('/[\r\n|]+/u',' ',$note));
        if(function_exists('mb_strlen')){
            if(mb_strlen($safe)>120)$safe=mb_substr($safe,0,120);
        } elseif(strlen($safe)>120) $safe=substr($safe,0,120);
        return '@SAV9|k='.$kind.'|g='.$goalId.'|f='.$fromAccount.'|t='.$toAccount.($safe!==''?'|n='.$safe:'');
    }

    public static function parseMarker(?string $note): array {
        $out=['tracked'=>false,'kind'=>null,'goal_id'=>0,'from_account_id'=>0,'to_account_id'=>0,'note'=>''];
        $note=(string)$note;
        // Compatibilidad con cualquier aporte hecho por V7 y V8.
        if(strpos($note,'@SAV9|')!==0 && strpos($note,'@SAV8|')!==0 && strpos($note,'@SAV7|')!==0) return $out;
        $out['tracked']=true;
        foreach(explode('|',$note) as $part){
            $pos=strpos($part,'=');
            if($pos===false) continue;
            $k=substr($part,0,$pos);$v=substr($part,$pos+1);
            if($k==='k')$out['kind']=$v;
            elseif($k==='g')$out['goal_id']=(int)$v;
            elseif($k==='f')$out['from_account_id']=(int)$v;
            elseif($k==='t')$out['to_account_id']=(int)$v;
            elseif($k==='n')$out['note']=$v;
        }
        return $out;
    }
}
