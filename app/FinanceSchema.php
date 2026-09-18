<?php
class FinanceSchema {
    public static function ensure(int $userId): void {
        static $done = [];
        $version='2026-09-18-quick-concepts-v1';
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

        // Accesos rápidos configurables: cada concepto puede marcarse como favorito
        // y conservar una posición de 1 a 5 dentro de su tipo (gasto o ingreso).
        if (self::tableExists('concepts')) {
            self::addColumnIfMissing('concepts', 'is_quick_access', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_ant_expense");
            self::addColumnIfMissing('concepts', 'quick_access_order', "TINYINT UNSIGNED DEFAULT NULL AFTER is_quick_access");
            self::addIndexIfMissing('concepts', 'idx_con_quick', 'user_id,is_quick_access,quick_access_order');
            self::ensureQuickConceptDefaults($userId);
        }

        // Fase 3: anulación segura. Nada se borra físicamente: las operaciones
        // anuladas quedan visibles para auditoría, pero dejan de afectar saldos.
        foreach (['transactions','account_transfers','account_adjustments','fund_allocations'] as $voidTable) {
            if (self::tableExists($voidTable)) {
                self::addColumnIfMissing($voidTable, 'voided_at', "DATETIME DEFAULT NULL");
                self::addColumnIfMissing($voidTable, 'voided_by_user_id', "INT UNSIGNED DEFAULT NULL");
                self::addColumnIfMissing($voidTable, 'void_reason', "VARCHAR(255) DEFAULT NULL");
            }
        }

        // Auditoría y cierre mensual: las tablas se crean con la estructura mínima
        // necesaria y sin claves foráneas obligatorias. En hosting compartido algunas
        // configuraciones de MySQL/MariaDB bloquean la petición si una FK no puede
        // crearse; la integridad de usuario ya se valida desde la aplicación.
        $pdo->exec("CREATE TABLE IF NOT EXISTS finance_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED DEFAULT NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            summary VARCHAR(500) DEFAULT NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            metadata_json LONGTEXT NULL,
            reversible TINYINT(1) NOT NULL DEFAULT 0,
            reversed_at DATETIME DEFAULT NULL,
            reversed_by_user_id INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_audit_user_date(user_id,created_at),
            KEY idx_audit_actor_date(actor_user_id,created_at),
            KEY idx_audit_entity(user_id,entity_type,entity_id),
            KEY idx_audit_reversible(user_id,reversible,reversed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_closures (
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
        if (self::tableExists('monthly_closures')) {
            self::addColumnIfMissing('monthly_closures', 'snapshot_hash', "CHAR(64) NOT NULL DEFAULT '' AFTER snapshot_json");
        }
        self::backfillAuditHistory($userId);

        // Pagos mensuales inteligentes: el monto/fecha del mes puede separarse del
        // valor recurrente, admite pagos parciales y permite omitir un mes sin
        // modificar los meses siguientes.
        if (self::tableExists('monthly_payments')) {
            self::addColumnIfMissing('monthly_payments', 'paid_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount");
            self::addColumnIfMissing('monthly_payments', 'amount_overridden', "TINYINT(1) NOT NULL DEFAULT 0 AFTER paid_amount");
            self::addColumnIfMissing('monthly_payments', 'due_date_overridden', "TINYINT(1) NOT NULL DEFAULT 0 AFTER amount_overridden");
            self::addColumnIfMissing('monthly_payments', 'skipped_at', "DATETIME DEFAULT NULL AFTER paid_at");
            self::addColumnIfMissing('monthly_payments', 'updated_by_user_id', "INT UNSIGNED DEFAULT NULL AFTER skipped_at");
            self::addColumnIfMissing('monthly_payments', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
            self::ensureMonthlyPaymentStatusEnum();
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_payment_parts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            monthly_payment_id BIGINT UNSIGNED NOT NULL,
            transaction_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            paid_at DATETIME NOT NULL,
            created_by_user_id INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_mpp_transaction(transaction_id),
            KEY idx_mpp_payment(monthly_payment_id),
            KEY idx_mpp_user_payment(user_id,monthly_payment_id),
            CONSTRAINT fk_mpp_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_mpp_payment FOREIGN KEY(monthly_payment_id) REFERENCES monthly_payments(id) ON DELETE CASCADE,
            CONSTRAINT fk_mpp_tx FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
            CONSTRAINT fk_mpp_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (self::tableExists('recurring_incomes')) {
            self::addColumnIfMissing('recurring_incomes', 'account_id', "INT UNSIGNED DEFAULT NULL AFTER concept_id");
        }

        self::addIndexIfMissing('transactions', 'idx_tx_account', 'account_id');
        self::addIndexIfMissing('transactions', 'idx_tx_fund', 'fund_id');
        if (self::tableExists('transactions')) self::addIndexIfMissing('transactions', 'idx_tx_voided', 'user_id,voided_at');
        if (self::tableExists('account_transfers')) self::addIndexIfMissing('account_transfers', 'idx_transfer_voided', 'user_id,voided_at');
        if (self::tableExists('account_adjustments')) self::addIndexIfMissing('account_adjustments', 'idx_adjustment_voided', 'user_id,voided_at');
        if (self::tableExists('fund_allocations')) self::addIndexIfMissing('fund_allocations', 'idx_allocation_voided', 'user_id,voided_at');
        self::addIndexIfMissing('recurring_payments', 'idx_rec_fund', 'fund_id');
        if (self::tableExists('recurring_incomes')) self::addIndexIfMissing('recurring_incomes', 'idx_ri_account', 'account_id');

        self::addForeignIfMissing('transactions', 'fk_tx_account', 'FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL');
        self::addForeignIfMissing('transactions', 'fk_tx_fund', 'FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL');
        // Las columnas de anulación funcionan sin FK. Evitamos crear estas claves
        // durante una petición web para no provocar HTTP 500 por restricciones del hosting.
        self::addForeignIfMissing('recurring_payments', 'fk_rec_fund', 'FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL');
        if (self::tableExists('recurring_incomes')) self::addForeignIfMissing('recurring_incomes', 'fk_ri_account', 'FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL');
        if (self::tableExists('monthly_payments')) {
            self::addIndexIfMissing('monthly_payments', 'idx_mp_smart_status', 'user_id,status,due_date');
            self::addForeignIfMissing('monthly_payments', 'fk_mp_updated_by', 'FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL');

            // Backfill no destructivo para pagos históricos: un pago que ya estaba
            // cerrado se considera totalmente cubierto. También registramos su
            // transacción histórica como una parte para mantener la trazabilidad.
            $pdo->exec("UPDATE monthly_payments mp
                LEFT JOIN transactions t ON t.id=mp.transaction_id AND t.user_id=mp.user_id AND t.voided_at IS NULL
                SET mp.paid_amount=CASE
                    WHEN mp.status='paid' AND mp.paid_amount=0 THEN COALESCE(t.amount,mp.amount)
                    ELSE mp.paid_amount END
                WHERE mp.status='paid'");
            if (self::tableExists('monthly_payment_parts')) {
                $pdo->exec("INSERT IGNORE INTO monthly_payment_parts
                    (user_id,monthly_payment_id,transaction_id,amount,paid_at,created_by_user_id,created_at)
                    SELECT mp.user_id,mp.id,mp.transaction_id,
                           COALESCE(t.amount,mp.amount),
                           COALESCE(mp.paid_at,t.occurred_at,mp.created_at),
                           COALESCE(t.created_by_user_id,t.user_id),
                           COALESCE(t.created_at,mp.created_at)
                    FROM monthly_payments mp
                    JOIN transactions t ON t.id=mp.transaction_id AND t.user_id=mp.user_id AND t.voided_at IS NULL
                    WHERE mp.status='paid' AND mp.transaction_id IS NOT NULL");
            }
        }

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

    private static function backfillAuditHistory(int $userId): void {
        $key='2026-09-15-audit-history-user-'.$userId;
        try {
            $st=db()->prepare('SELECT 1 FROM finance_migrations WHERE migration_key=? LIMIT 1');
            $st->execute([$key]);
            if($st->fetchColumn()) return;
            $pdo=db();

            // La auditoría detallada empieza con esta versión, pero mostramos también
            // los movimientos financieros ya existentes como historial no reversible.
            $q=$pdo->prepare("INSERT INTO finance_audit_log
                (user_id,actor_user_id,action,entity_type,entity_id,title,summary,reversible,created_at)
                SELECT t.user_id,COALESCE(t.created_by_user_id,t.user_id),'historical_transaction','transaction',t.id,
                    CASE WHEN t.type='income' THEN 'Ingreso histórico' ELSE 'Gasto histórico' END,
                    CONCAT(COALESCE(co.name,c.name,'Movimiento'),' · S/ ',CAST(t.amount AS CHAR)),0,COALESCE(t.created_at,t.occurred_at)
                FROM transactions t
                LEFT JOIN categories c ON c.id=t.category_id
                LEFT JOIN concepts co ON co.id=t.concept_id
                WHERE t.user_id=? AND NOT EXISTS (
                    SELECT 1 FROM finance_audit_log a WHERE a.user_id=t.user_id AND a.entity_type='transaction' AND a.entity_id=t.id
                )");
            $q->execute([$userId]);

            if(self::tableExists('account_transfers')){
                $q=$pdo->prepare("INSERT INTO finance_audit_log
                    (user_id,actor_user_id,action,entity_type,entity_id,title,summary,reversible,created_at)
                    SELECT tr.user_id,COALESCE(tr.created_by_user_id,tr.user_id),'historical_transfer','account_transfer',tr.id,
                        'Transferencia histórica',CONCAT(COALESCE(a1.name,'Cuenta'),' → ',COALESCE(a2.name,'Cuenta'),' · S/ ',CAST(tr.amount AS CHAR)),0,COALESCE(tr.created_at,tr.occurred_at)
                    FROM account_transfers tr
                    LEFT JOIN financial_accounts a1 ON a1.id=tr.from_account_id
                    LEFT JOIN financial_accounts a2 ON a2.id=tr.to_account_id
                    WHERE tr.user_id=? AND NOT EXISTS (
                        SELECT 1 FROM finance_audit_log a WHERE a.user_id=tr.user_id AND a.entity_type='account_transfer' AND a.entity_id=tr.id
                    )");
                $q->execute([$userId]);
            }
            if(self::tableExists('account_adjustments')){
                $q=$pdo->prepare("INSERT INTO finance_audit_log
                    (user_id,actor_user_id,action,entity_type,entity_id,title,summary,reversible,created_at)
                    SELECT ad.user_id,COALESCE(ad.created_by_user_id,ad.user_id),'historical_adjustment','account_adjustment',ad.id,
                        'Ajuste histórico de cuenta',CONCAT(COALESCE(a.name,'Cuenta'),' · S/ ',CAST(ad.amount AS CHAR)),0,COALESCE(ad.created_at,ad.occurred_at)
                    FROM account_adjustments ad LEFT JOIN financial_accounts a ON a.id=ad.account_id
                    WHERE ad.user_id=? AND NOT EXISTS (
                        SELECT 1 FROM finance_audit_log al WHERE al.user_id=ad.user_id AND al.entity_type='account_adjustment' AND al.entity_id=ad.id
                    )");
                $q->execute([$userId]);
            }
            if(self::tableExists('fund_allocations')){
                $q=$pdo->prepare("INSERT INTO finance_audit_log
                    (user_id,actor_user_id,action,entity_type,entity_id,title,summary,reversible,created_at)
                    SELECT fa.user_id,COALESCE(fa.created_by_user_id,fa.user_id),'historical_fund_allocation','fund_allocation',fa.id,
                        CASE WHEN fa.amount>=0 THEN 'Separación histórica en fondo' ELSE 'Liberación histórica de fondo' END,
                        CONCAT(COALESCE(f.name,'Fondo'),' · S/ ',CAST(ABS(fa.amount) AS CHAR)),0,COALESCE(fa.created_at,fa.occurred_at)
                    FROM fund_allocations fa LEFT JOIN funds f ON f.id=fa.fund_id
                    WHERE fa.user_id=? AND NOT EXISTS (
                        SELECT 1 FROM finance_audit_log a WHERE a.user_id=fa.user_id AND a.entity_type='fund_allocation' AND a.entity_id=fa.id
                    )");
                $q->execute([$userId]);
            }
            $ins=$pdo->prepare('INSERT IGNORE INTO finance_migrations(migration_key,applied_at) VALUES(?,NOW())');
            $ins->execute([$key]);
        } catch(Throwable $e) {
            // La falta de un backfill histórico nunca debe bloquear el sistema.
            error_log('[MiDinero audit backfill] '.$e->getMessage());
        }
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

    private static function ensureQuickConceptDefaults(int $userId): void {
        $key='2026-09-18-quick-concepts-user-'.$userId;
        try {
            $st=db()->prepare('SELECT 1 FROM finance_migrations WHERE migration_key=? LIMIT 1');
            $st->execute([$key]);
            if ($st->fetchColumn()) return;

            $pdo=db();
            $count=$pdo->prepare("SELECT COUNT(*)
                FROM concepts co
                JOIN categories c ON c.id=co.category_id
                WHERE co.user_id=? AND co.active=1 AND c.type=? AND co.is_quick_access=1");
            $pick=$pdo->prepare("SELECT co.id
                FROM concepts co
                JOIN categories c ON c.id=co.category_id
                WHERE co.user_id=? AND co.active=1 AND c.type=?
                ORDER BY co.name
                LIMIT 5");
            $mark=$pdo->prepare('UPDATE concepts SET is_quick_access=1,quick_access_order=? WHERE id=? AND user_id=?');

            foreach (['expense','income'] as $type) {
                $count->execute([$userId,$type]);
                if ((int)$count->fetchColumn() > 0) continue;
                $pick->execute([$userId,$type]);
                $order=1;
                foreach ($pick->fetchAll(PDO::FETCH_COLUMN) as $conceptId) {
                    $mark->execute([$order++,(int)$conceptId,$userId]);
                }
            }

            $done=$pdo->prepare('INSERT IGNORE INTO finance_migrations(migration_key) VALUES(?)');
            $done->execute([$key]);
        } catch (Throwable $e) {
            // Los accesos rápidos no deben impedir el inicio del sistema.
            error_log('[Finanzapp quick concepts] '.$e->getMessage());
        }
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
    private static function ensureMonthlyPaymentStatusEnum(): void {
        try {
            $st=db()->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_payments' AND COLUMN_NAME='status' LIMIT 1");
            $st->execute();
            $type=(string)($st->fetchColumn() ?: '');
            if ($type !== '' && (strpos($type,"'partial'")===false || strpos($type,"'skipped'")===false)) {
                db()->exec("ALTER TABLE monthly_payments MODIFY status ENUM('pending','partial','paid','skipped') NOT NULL DEFAULT 'pending'");
            }
        } catch (Throwable $e) {
            // Si el hosting no permite ALTER, el resto de la aplicación sigue
            // funcionando con pagos completos. La interfaz reportará el error
            // al intentar usar una función inteligente.
        }
    }
    private static function ensureDefaultAccount(int $userId): int {
        $st = db()->prepare('SELECT id FROM financial_accounts WHERE user_id=? AND active=1 ORDER BY id LIMIT 1');
        $st->execute([$userId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id) return $id;

        // Compensa movimientos históricos negativos para que la migración no arranque mostrando deuda ficticia.
        $netSt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE user_id=? AND voided_at IS NULL");
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
            COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=f.user_id AND fa.fund_id=f.id AND fa.voided_at IS NULL),0) allocated,
            COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id=f.user_id AND t.fund_id=f.id AND t.type='expense' AND t.voided_at IS NULL),0) spent
            FROM funds f WHERE f.user_id=?");
        $st->execute([$userId]);
        $up = db()->prepare("UPDATE transactions SET fund_id=NULL WHERE id=? AND user_id=? AND type='expense'");
        $rows = db()->prepare("SELECT id,amount FROM transactions WHERE user_id=? AND fund_id=? AND type='expense' AND voided_at IS NULL ORDER BY occurred_at DESC,id DESC");
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
