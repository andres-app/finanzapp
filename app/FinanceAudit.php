<?php
class FinanceAudit {
    public static function record(
        int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $title,
        ?string $summary = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        bool $reversible = false
    ): int {
        FinanceSchema::ensure($userId);
        $actor=actual_user_id() ?: $userId;
        $st=db()->prepare("INSERT INTO finance_audit_log
            (user_id,actor_user_id,action,entity_type,entity_id,title,summary,before_json,after_json,metadata_json,reversible,created_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $enc=static fn(?array $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $st->execute([
            $userId,$actor,substr($action,0,80),substr($entityType,0,80),$entityId,
            substr($title,0,180),$summary!==null?substr($summary,0,500):null,
            $enc($before),$enc($after),$enc($metadata),$reversible?1:0
        ]);
        return (int)db()->lastInsertId();
    }

    public static function list(int $userId, array $filters=[]): array {
        FinanceSchema::ensure($userId);
        $where=['a.user_id=?'];$params=[$userId];
        $period=trim((string)($filters['period']??''));
        if(preg_match('/^\d{4}-\d{2}$/',$period)){
            [$start,$end]=month_range($period);
            $where[]='a.created_at>=?';$params[]=$start;
            $where[]='a.created_at<?';$params[]=$end;
        }
        $actor=(int)($filters['actor_user_id']??0);
        if($actor>0){$where[]='a.actor_user_id=?';$params[]=$actor;}
        $entity=trim((string)($filters['entity_type']??''));
        if($entity!==''){$where[]='a.entity_type=?';$params[]=$entity;}
        $limit=max(1,min(300,(int)($filters['limit']??120)));
        $sql="SELECT a.*,u.name actor_name,ru.name reversed_by_name
            FROM finance_audit_log a
            LEFT JOIN users u ON u.id=a.actor_user_id
            LEFT JOIN users ru ON ru.id=a.reversed_by_user_id
            WHERE ".implode(' AND ',$where)."
            ORDER BY a.created_at DESC,a.id DESC LIMIT {$limit}";
        $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
        foreach($rows as &$r){
            foreach(['before_json'=>'before','after_json'=>'after','metadata_json'=>'metadata'] as $src=>$dst){
                $decoded=json_decode((string)($r[$src]??''),true);$r[$dst]=is_array($decoded)?$decoded:[];
            }
        }unset($r);
        return $rows;
    }

    public static function getForUpdate(int $userId,int $auditId): ?array {
        $st=db()->prepare('SELECT * FROM finance_audit_log WHERE id=? AND user_id=? FOR UPDATE');
        $st->execute([$auditId,$userId]);
        $row=$st->fetch();
        if(!$row)return null;
        foreach(['before_json'=>'before','after_json'=>'after','metadata_json'=>'metadata'] as $src=>$dst){
            $d=json_decode((string)($row[$src]??''),true);$row[$dst]=is_array($d)?$d:[];
        }
        return $row;
    }

    public static function markReversed(int $userId,int $auditId): void {
        $st=db()->prepare('UPDATE finance_audit_log SET reversed_at=NOW(),reversed_by_user_id=? WHERE id=? AND user_id=? AND reversed_at IS NULL');
        $st->execute([actual_user_id()?:$userId,$auditId,$userId]);
    }

    public static function members(int $userId): array {
        return HouseholdSchema::membersForUser(actual_user_id()?:$userId);
    }
}
