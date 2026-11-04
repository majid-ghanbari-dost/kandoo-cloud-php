<?php

declare(strict_types=1);

namespace Kandoo\Cloud;

/**
 * Ordered .sql migrations tracked in a `schema_migrations` table (PM ORDER 9 P-02).
 *
 * Files live in <app root>/migrations/ named NNNN_description.sql and are applied in
 * lexicographic order, once each, tracked by file name. MariaDB DDL is not transactional,
 * so each file is executed statement-by-statement and recorded immediately afterwards;
 * re-running `bin/migrate.php` is always safe (idempotent).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Migrator
{
    public function __construct(
        private Database $db,
        private ?string $migrationsDir = null,
    ) {
    }

    private function dir(): string
    {
        return $this->migrationsDir ?? (kandoo_app_root() . '/migrations');
    }

    public function ensureMigrationsTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(191) NOT NULL,
                applied_at DATETIME     NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
        );
    }

    /** @return list<string> migration file names already applied */
    public function applied(): array
    {
        $this->ensureMigrationsTable();
        $rows = $this->db->pdo()->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll();
        return array_map(static fn (array $row): string => (string) $row['version'], $rows);
    }

    /** @return list<string> pending migration file names, in application order */
    public function pending(): array
    {
        $applied = array_flip($this->applied());
        $files = [];
        foreach (scandir($this->dir()) ?: [] as $name) {
            if (preg_match('/^\d{4}_.+\.sql$/', $name) === 1 && !isset($applied[$name])) {
                $files[] = $name;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Applies every pending migration in order and returns the applied file names.
     * @return list<string>
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $pdo = $this->db->pdo();
        $appliedNow = [];
        foreach ($this->pending() as $name) {
            $sql = file_get_contents($this->dir() . '/' . $name);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration $name");
            }
            foreach (self::splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }
            $insert = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $insert->execute([$name, gmdate('Y-m-d H:i:s')]);
            $appliedNow[] = $name;
        }
        return $appliedNow;
    }

    /**
     * Splits a migration file into statements: strips `--` comment lines and splits on
     * semicolons outside single-quoted strings. Migration files are authored one
     * statement per block, so no stored-program syntax is needed.
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $sql) ?: [] as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || $trimmed === '') {
                continue;
            }
            $lines[] = $line;
        }
        $clean = implode("\n", $lines);

        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($clean);
        for ($i = 0; $i < $length; $i++) {
            $ch = $clean[$i];
            if ($inString) {
                $current .= $ch;
                if ($ch === "'") {
                    if ($i + 1 < $length && $clean[$i + 1] === "'") { // escaped ''
                        $current .= "'";
                        $i++;
                    } else {
                        $inString = false;
                    }
                }
                continue;
            }
            if ($ch === "'") {
                $inString = true;
                $current .= $ch;
                continue;
            }
            if ($ch === ';') {
                $statement = trim($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $statement = trim($current);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        return $statements;
    }
}
