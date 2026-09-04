<?php
class FinanceSchema {
    public static function ensure(int $userId): void {
        static $done = [];
        $version='2026-09-02-savings-v6-stable';
        if (isset($done[$userId]) || (isset($_SESSION['finance_schema_version']) && $_SESSION['finance_schema_version']===$version)) return;
        $pdo = db();

        $pdo->exec("CREATE TABLE IF NOT EXISTS finance_migrations (
            migration_key VARCHAR(120) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(migration_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS financial_accounts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            account_type ENUM('bank','wallet','cash','other') NOT NULL DEFAULT 'bank',
            icon VARCHAR(20) NOT NULL DEFAULT '🏦',
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            color VARCHAR(20) NOT NULL DEFAULT '#111827',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_fa_user(user_id,active),
            CONSTRAINT fk_fa_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS funds (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            icon VARCHAR(20) NOT NULL DEFAULT '💰',
            color VARCHAR(20) NOT NULL DEFAULT '#6b7280',
            target_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_fund_user(user_id,active),
            CONSTRAINT fk_fund_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS fund_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            fund_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            occurred_at DATETIME NOT NULL,
            source_transaction_id BIGINT UNSIGNED DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_alloc_user_date(user_id,occurred_at),
            KEY idx_alloc_fund(fund_id),
            KEY idx_alloc_source(source_transaction_id),
            CONSTRAINT fk_alloc_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_alloc_fund FOREIGN KEY(fund_id) REFERENCES funds(id) ON DELETE CASCADE,
            CONSTRAINT fk_alloc_source FOREIGN KEY(source_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS account_transfers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            from_account_id INT UNSIGNED NOT NULL,
            to_account_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            occurred_at DATETIME NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_transfer_user_date(user_id,occurred_at),
            KEY idx_transfer_from(from_account_id),
            KEY idx_transfer_to(to_account_id),
            CONSTRAINT fk_transfer_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_transfer_from FOREIGN KEY(from_account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_transfer_to FOREIGN KEY(to_account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS account_adjustments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            occurred_at DATETIME NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_adj_user_date(user_id,occurred_at),
            KEY idx_adj_account(account_id),
            CONSTRAINT fk_adj_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_adj_account FOREIGN KEY(account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_incomes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            concept_id INT UNSIGNED DEFAULT NULL,
            account_id INT UNSIGNED DEFAULT NULL,
            name VARCHAR(140) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            income_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
            icon VARCHAR(20) NOT NULL DEFAULT '💰',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_ri_user_active(user_id,active),
            KEY fk_ri_category(category_id),
            KEY fk_ri_concept(concept_id),
            KEY idx_ri_account(account_id),
            CONSTRAINT fk_ri_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ri_category FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ri_concept FOREIGN KEY(concept_id) REFERENCES concepts(id) ON DELETE SET NULL,
            CONSTRAINT fk_ri_account FOREIGN KEY(account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (self::tableExists('recurring_incomes')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_income_expectations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                recurring_id INT UNSIGNED NOT NULL,
                period CHAR(7) NOT NULL,
                due_date DATE NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE KEY uniq_month_income(user_id,recurring_id,period),
                KEY idx_mie_user_period(user_id,period),
                CONSTRAINT fk_mie_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mie_recurring FOREIGN KEY(recurring_id) REFERENCES recurring_incomes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Ahorro v6 se administra en SavingsSchema sin alterar la tabla goals.
        // Esto evita que una migración de ahorro pueda bloquear el resto del sistema.

        self::addColumnIfMissing('transactions', 'account_id', "INT UNSIGNED DEFAULT NULL AFTER concept_id");
        self::addColumnIfMissing('transactions', 'fund_id', "INT UNSIGNED DEFAULT NULL AFTER account_id");
        self::addColumnIfMissing('recurring_payments', 'fund_id', "INT UNSIGNED DEFAULT NULL AFTER concept_id");
        if (self::tableExists('recurring_incomes')) {
            self::addColumnIfMissing('recurring_incomes', 'account_id', "INT UNSIGNED DEFAULT NULL AFTER concept_id");
        }

        self::addIndexIfMissing('transactions', 'idx_tx_account', 'account_id');
        self::addIndexIfMissing('transactions', 'idx_tx_fund', 'fund_id');
        self::addIndexIfMissing('recurring_payments', 'idx_rec_fund', 'fund_id');
        if (self::tableExists('recurring_incomes')) self::addIndexIfMissing('recurring_incomes', 'idx_ri_account', 'account_id');

        self::addForeignIfMissing('transactions', 'fk_tx_account', 'FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL');
        self::addForeignIfMissing('transactions', 'fk_tx_fund', 'FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL');
        self::addForeignIfMissing('recurring_payments', 'fk_rec_fund', 'FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL');
        if (self::tableExists('recurring_incomes')) self::addForeignIfMissing('recurring_incomes', 'fk_ri_account', 'FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL');

        // v4.1: no modificar fechas ni históricos automáticamente al navegar.
        // Las migraciones de datos deben ejecutarse de forma explícita y con respaldo.

        $defaultAccount = self::ensureDefaultAccount($userId);
        $st = $pdo->prepare('UPDATE transactions SET account_id=? WHERE user_id=? AND account_id IS NULL');
        $st->execute([$defaultAccount, $userId]);
        if (self::tableExists('recurring_incomes')) {
            $st = $pdo->prepare('UPDATE recurring_incomes SET account_id=? WHERE user_id=? AND account_id IS NULL');
            $st->execute([$defaultAccount, $userId]);
        }
        self::ensureDefaultFunds($userId);
        self::linkRecurringFunds($userId);

        // Versiones anteriores podían crear obligaciones al navegar hacia meses
        // anteriores a la fecha en que el pago fijo fue creado. Esas filas son
        // obligaciones fantasma: nunca fueron pagadas ni existían aún en el sistema.
        if (self::tableExists('monthly_payments') && self::tableExists('recurring_payments')) {
            $cleanup = $pdo->prepare("DELETE mp FROM monthly_payments mp
                JOIN recurring_payments r ON r.id=mp.recurring_id AND r.user_id=mp.user_id
                WHERE mp.user_id=? AND mp.status='pending' AND mp.transaction_id IS NULL
                  AND mp.period < DATE_FORMAT(r.created_at,'%Y-%m')");
            $cleanup->execute([$userId]);
        }
        if (self::tableExists('monthly_income_expectations') && self::tableExists('recurring_incomes')) {
            $cleanup = $pdo->prepare("DELETE mie FROM monthly_income_expectations mie
                JOIN recurring_incomes r ON r.id=mie.recurring_id AND r.user_id=mie.user_id
                WHERE mie.user_id=? AND mie.period < DATE_FORMAT(r.created_at,'%Y-%m')");
            $cleanup->execute([$userId]);
        }

        // v4.1: no reasignar movimientos históricos automáticamente.
        // Las validaciones bloquean nuevos sobregiros de fondos; la reparación histórica
        // se deja para una acción de auditoría explícita.

        $done[$userId] = true;
        $_SESSION['finance_schema_version']=$version;
    }

    private static function applyLegacyIntegrityMigration(int $userId): void {
        $key='2026-09-02-integridad-v4';
        $st=db()->prepare('SELECT 1 FROM finance_migrations WHERE migration_key=? LIMIT 1');
        $st->execute([$key]);
        if($st->fetchColumn()) return;

        $pdo=db();
        // Solo filas antiguas generadas por los flujos automáticos: ocurrieron a la
        // misma hora UTC que created_at. Los movimientos manuales ya tenían hora Lima
        // y por eso difieren aproximadamente cinco horas.
        if(self::tableExists('monthly_payments')){
            $pdo->exec("UPDATE monthly_payments mp JOIN transactions t ON t.id=mp.transaction_id AND t.user_id=mp.user_id SET mp.amount=t.amount WHERE mp.status='paid' AND mp.amount=0");
            $pdo->exec("UPDATE transactions t JOIN monthly_payments mp ON mp.transaction_id=t.id AND mp.user_id=t.user_id
                SET t.occurred_at=DATE_SUB(t.occurred_at,INTERVAL 5 HOUR),
                    mp.paid_at=CASE WHEN mp.paid_at IS NULL THEN NULL ELSE DATE_SUB(mp.paid_at,INTERVAL 5 HOUR) END
                WHERE ABS(TIMESTAMPDIFF(SECOND,t.occurred_at,t.created_at))<=5");
        }
        if(self::tableExists('fund_allocations')){
            $pdo->exec("UPDATE fund_allocations SET occurred_at=DATE_SUB(occurred_at,INTERVAL 5 HOUR) WHERE ABS(TIMESTAMPDIFF(SECOND,occurred_at,created_at))<=5");
        }
        $ins=$pdo->prepare('INSERT IGNORE INTO finance_migrations(migration_key,applied_at) VALUES(?,NOW())');
        $ins->execute([$key]);
    }

    private static function tableExists(string $table): bool {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }
    private static function columnExists(string $table, string $column): bool {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$table,$column]);
        return (bool)$st->fetchColumn();
    }
    private static function addColumnIfMissing(string $table, string $column, string $definition): void {
        if (!self::columnExists($table,$column)) db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
    private static function addIndexIfMissing(string $table, string $index, string $columns): void {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $st->execute([$table,$index]);
        if (!$st->fetchColumn()) db()->exec("ALTER TABLE `$table` ADD INDEX `$index` ($columns)");
    }
    private static function addForeignIfMissing(string $table, string $constraint, string $definition): void {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
        $st->execute([$table,$constraint]);
        if (!$st->fetchColumn()) {
            try { db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraint` $definition"); } catch (Throwable $e) { /* no bloquear despliegue */ }
        }
    }
    private static function ensureDefaultAccount(int $userId): int {
        $st = db()->prepare('SELECT id FROM financial_accounts WHERE user_id=? AND active=1 ORDER BY id LIMIT 1');
        $st->execute([$userId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id) return $id;

        // Compensa movimientos históricos negativos para que la migración no arranque mostrando deuda ficticia.
        $netSt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE user_id=?");
        $netSt->execute([$userId]);
        $net = (float)$netSt->fetchColumn();
        $opening = $net < 0 ? abs($net) : 0;
        $ins = db()->prepare("INSERT INTO financial_accounts(user_id,name,account_type,icon,opening_balance,color) VALUES(?, 'Cuenta principal', 'bank', '🏦', ?, '#111827')");
        $ins->execute([$userId,$opening]);
        return (int)db()->lastInsertId();
    }
    private static function ensureDefaultFunds(int $userId): void {
        $st = db()->prepare('SELECT COUNT(*) FROM funds WHERE user_id=?');
        $st->execute([$userId]);
        if ((int)$st->fetchColumn() > 0) return;
        $rows = [
            ['Emergencia','🛡️','#4f46e5'],['Casa','🏠','#7c3aed'],['Servicios','💡','#ca8a04'],['Alimentación','🍽️','#0f766e'],
            ['Delivery','🍔','#ea580c'],['Salidas','🎉','#db2777'],['Ahorro','💰','#15803d'],['Colegio','🎓','#2563eb']
        ];
        $ins = db()->prepare('INSERT INTO funds(user_id,name,icon,color,target_amount) VALUES(?,?,?,?,0)');
        foreach ($rows as $r) $ins->execute([$userId,$r[0],$r[1],$r[2]]);
    }
    private static function repairNegativeFunds(int $userId): void {
        $st = db()->prepare("SELECT f.id,
            COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=f.user_id AND fa.fund_id=f.id),0) allocated,
            COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense'),0) spent
            FROM funds f WHERE f.user_id=?");
        $st->execute([$userId]);
        $up = db()->prepare("UPDATE transactions SET fund_id=NULL WHERE id=? AND user_id=? AND type='expense'");
        $rows = db()->prepare("SELECT id,amount FROM transactions WHERE user_id=? AND fund_id=? AND type='expense' ORDER BY occurred_at DESC,id DESC");
        foreach ($st->fetchAll() as $f) {
            $deficit = (float)$f['spent'] - (float)$f['allocated'];
            if ($deficit <= 0.005) continue;
            $rows->execute([$userId,$f['id']]);
            foreach ($rows->fetchAll() as $tx) {
                if ($deficit <= 0.005) break;
                $up->execute([$tx['id'],$userId]);
                $deficit -= (float)$tx['amount'];
            }
        }
    }

    public static function ensureSavingsLinks(int $userId): void {
        try { SavingsSchema::ensure($userId); } catch (Throwable $e) { error_log('[MiDinero savings schema] '.$e->getMessage()); }
    }

    private static function linkRecurringFunds(int $userId): void {
        $funds=db()->prepare('SELECT id,name FROM funds WHERE user_id=? AND active=1');$funds->execute([$userId]);$map=[];foreach($funds->fetchAll() as $f)$map[mb_strtolower($f['name'])]=(int)$f['id'];
        $rows=db()->prepare('SELECT r.id,c.name category FROM recurring_payments r JOIN categories c ON c.id=r.category_id WHERE r.user_id=? AND r.active=1 AND r.fund_id IS NULL');$rows->execute([$userId]);$up=db()->prepare('UPDATE recurring_payments SET fund_id=? WHERE id=? AND user_id=?');
        foreach($rows->fetchAll() as $r){$cat=mb_strtolower($r['category']);$fid=null;if(strpos($cat,'casa')!==false)$fid=$map['casa']??null;elseif(strpos($cat,'educ')!==false)$fid=$map['colegio']??null;elseif(strpos($cat,'serv')!==false||strpos($cat,'celular')!==false)$fid=$map['servicios']??null;if($fid)$up->execute([$fid,$r['id'],$userId]);}
    }
}
