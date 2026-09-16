<?php
class HouseholdSchema {
    private static bool $schemaReady = false;

    public static function ensureSchema(): void {
        if (self::$schemaReady) return;
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS households (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(140) NOT NULL,
            owner_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_household_owner(owner_user_id),
            CONSTRAINT fk_household_owner FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS household_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            household_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_household_member_user(user_id),
            KEY idx_household_members_household(household_id),
            CONSTRAINT fk_household_member_household FOREIGN KEY(household_id) REFERENCES households(id) ON DELETE CASCADE,
            CONSTRAINT fk_household_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach (['transactions','account_transfers','account_adjustments','fund_allocations'] as $table) {
            if (self::tableExists($table) && !self::columnExists($table,'created_by_user_id')) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER user_id");
            }
            if (self::tableExists($table) && self::columnExists($table,'created_by_user_id')) {
                $pdo->exec("UPDATE `$table` SET created_by_user_id=user_id WHERE created_by_user_id IS NULL");
                self::addIndexIfMissing($table,'idx_'.$table.'_actor','created_by_user_id');
            }
        }
        self::$schemaReady = true;
    }

    public static function ensureForUser(int $userId): array {
        self::ensureSchema();
        $ctx = self::contextRaw($userId);
        if ($ctx) return $ctx;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $u = $pdo->prepare('SELECT id,name FROM users WHERE id=? FOR UPDATE');
            $u->execute([$userId]);
            $user = $u->fetch();
            if (!$user) throw new RuntimeException('Usuario no válido.');
            $ctx = self::contextRaw($userId);
            if (!$ctx) {
                $name = 'Familia de '.trim((string)$user['name']);
                $h = $pdo->prepare('INSERT INTO households(name,owner_user_id) VALUES(?,?)');
                $h->execute([$name,$userId]);
                $hid = (int)$pdo->lastInsertId();
                $m = $pdo->prepare("INSERT INTO household_members(household_id,user_id,role) VALUES(?,?,'owner')");
                $m->execute([$hid,$userId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Otro request pudo crear el hogar al mismo tiempo.
            $ctx = self::contextRaw($userId);
            if (!$ctx) throw $e;
        }
        return self::contextRaw($userId) ?: throw new RuntimeException('No se pudo preparar el hogar.');
    }

    public static function contextForUser(int $userId): array {
        return self::ensureForUser($userId);
    }

    public static function scopeOwnerUserId(int $userId): int {
        $ctx = self::ensureForUser($userId);
        return (int)$ctx['owner_user_id'];
    }

    public static function membersForUser(int $userId): array {
        $ctx = self::ensureForUser($userId);
        $st = db()->prepare("SELECT hm.user_id,hm.role,hm.joined_at,u.name,u.email
            FROM household_members hm JOIN users u ON u.id=hm.user_id
            WHERE hm.household_id=? ORDER BY FIELD(hm.role,'owner','admin','member'),u.name");
        $st->execute([(int)$ctx['household_id']]);
        return $st->fetchAll();
    }

    public static function canManage(int $userId): bool {
        $ctx = self::ensureForUser($userId);
        return in_array($ctx['role'],['owner','admin'],true);
    }

    public static function linkExistingUser(int $actorUserId, string $email): array {
        $email = trim(mb_strtolower($email));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Ingresa un correo válido.');
        $ctx = self::ensureForUser($actorUserId);
        if (!in_array($ctx['role'],['owner','admin'],true)) throw new DomainException('No tienes permiso para agregar integrantes.');

        $pdo = db();
        $u = $pdo->prepare('SELECT id,name,email FROM users WHERE LOWER(email)=? LIMIT 1');
        $u->execute([$email]);
        $target = $u->fetch();
        if (!$target) throw new DomainException('Ese correo todavía no tiene una cuenta en Finanzapp. Crea primero su usuario y vuelve a intentarlo.');
        $targetId = (int)$target['id'];
        if ($targetId === $actorUserId) throw new DomainException('Ese usuario ya pertenece a este hogar.');

        $targetCtx = self::ensureForUser($targetId);
        if ((int)$targetCtx['household_id'] === (int)$ctx['household_id']) return $target;
        if (self::hasMeaningfulOwnData($targetId)) {
            throw new DomainException('Ese usuario ya tiene movimientos o configuración financiera propia. Para no mezclar datos, primero debe quedar sin actividad o migrarse de forma explícita.');
        }

        $pdo->beginTransaction();
        try {
            $targetCtx = self::contextRaw($targetId);
            if ($targetCtx && (int)$targetCtx['household_id'] !== (int)$ctx['household_id']) {
                $count = $pdo->prepare('SELECT COUNT(*) FROM household_members WHERE household_id=? AND user_id<>?');
                $count->execute([(int)$targetCtx['household_id'],$targetId]);
                if ((int)$count->fetchColumn() > 0) throw new DomainException('Ese usuario ya administra otro hogar con integrantes.');
                $pdo->prepare('DELETE FROM household_members WHERE user_id=?')->execute([$targetId]);
                $pdo->prepare('DELETE FROM households WHERE id=? AND owner_user_id=?')->execute([(int)$targetCtx['household_id'],$targetId]);
            }
            $ins = $pdo->prepare("INSERT INTO household_members(household_id,user_id,role) VALUES(?,?,'admin')");
            $ins->execute([(int)$ctx['household_id'],$targetId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $target;
    }

    public static function removeMember(int $actorUserId, int $memberUserId): void {
        $ctx = self::ensureForUser($actorUserId);
        if ($ctx['role'] !== 'owner') throw new DomainException('Solo el propietario del hogar puede retirar integrantes.');
        if ($memberUserId === (int)$ctx['owner_user_id']) throw new DomainException('El propietario no puede retirarse del hogar.');
        $st = db()->prepare('DELETE FROM household_members WHERE household_id=? AND user_id=?');
        $st->execute([(int)$ctx['household_id'],$memberUserId]);
        if (!$st->rowCount()) throw new DomainException('Integrante no encontrado.');
    }

    public static function rename(int $actorUserId, string $name): void {
        $ctx = self::ensureForUser($actorUserId);
        if (!in_array($ctx['role'],['owner','admin'],true)) throw new DomainException('No tienes permiso para cambiar el nombre del hogar.');
        $name = trim($name);
        if ($name === '') throw new DomainException('Escribe un nombre para el hogar.');
        db()->prepare('UPDATE households SET name=? WHERE id=?')->execute([$name,(int)$ctx['household_id']]);
    }

    private static function contextRaw(int $userId): ?array {
        $st = db()->prepare("SELECT h.id household_id,h.name household_name,h.owner_user_id,hm.role
            FROM household_members hm JOIN households h ON h.id=hm.household_id WHERE hm.user_id=? LIMIT 1");
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    }

    private static function hasMeaningfulOwnData(int $userId): bool {
        $pdo = db();
        $checks = [
            ['transactions','user_id'],['account_transfers','user_id'],['account_adjustments','user_id'],['fund_allocations','user_id'],
            ['recurring_payments','user_id'],['recurring_incomes','user_id'],['goals','user_id']
        ];
        foreach ($checks as [$table,$col]) {
            if (!self::tableExists($table)) continue;
            $st=$pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col`=?");$st->execute([$userId]);
            if ((int)$st->fetchColumn()>0) return true;
        }
        if (self::tableExists('financial_accounts')) {
            $st=$pdo->prepare('SELECT COUNT(*) FROM financial_accounts WHERE user_id=? AND ABS(opening_balance)>0.005');$st->execute([$userId]);
            if ((int)$st->fetchColumn()>0) return true;
        }
        return false;
    }

    private static function tableExists(string $table): bool {
        $st=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$st->execute([$table]);return (bool)$st->fetchColumn();
    }
    private static function columnExists(string $table,string $column): bool {
        $st=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$st->execute([$table,$column]);return (bool)$st->fetchColumn();
    }
    private static function addIndexIfMissing(string $table,string $index,string $columns): void {
        $st=db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$st->execute([$table,$index]);if(!$st->fetchColumn())db()->exec("ALTER TABLE `$table` ADD INDEX `$index` ($columns)");
    }
}
