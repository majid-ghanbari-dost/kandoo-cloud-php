<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Operations;

/** Local, portable InnoDB logical snapshot; restore is deliberately limited to isolated TEST_RESTORE databases. */
final class LogicalBackup
{
    private static function ident(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || str_contains($name, "\0")) { throw new \RuntimeException('Invalid identifier'); }
        return '`'.str_replace('`','``',$name).'`';
    }

    /** Output directory must already exist outside the application tree, and be private. */
    public static function create(\PDO $pdo, string $directory): array
    {
        $dir=realpath($directory); $root=realpath(kandoo_app_root());
        if ($dir === false || $root === false || !is_dir($dir) || !is_writable($dir)
            || $dir === $root || str_starts_with($dir,$root.DIRECTORY_SEPARATOR)
            || ((fileperms($dir) & 0077) !== 0)) { throw new \RuntimeException('Backup directory must be private and outside the application'); }
        $database=$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') { throw new \RuntimeException('No source database selected'); }
        // This format intentionally excludes routines, views, triggers, events and non-transactional tables.
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND (TABLE_TYPE <> 'BASE TABLE' OR ENGINE <> 'InnoDB')"); $q->execute([$database]);
        if ((int)$q->fetchColumn() !== 0) { throw new \RuntimeException('Unsupported table engine or view'); }
        foreach (['TRIGGERS'=>'TRIGGER_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $table=>$col) {
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$col.'=?'); $q->execute([$database]);
            if ((int)$q->fetchColumn() !== 0) { throw new \RuntimeException('Unsupported database objects'); }
        }
        $file=$dir.'/kandoo-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(12)).'.jsonl';
        $fp=@fopen($file,'x+b'); if ($fp === false) { throw new \RuntimeException('Cannot create backup'); }
        if (!@chmod($file,0600) || ((fileperms($file) & 0077) !== 0)) { fclose($fp); @unlink($file); throw new \RuntimeException('Cannot protect backup'); }
        $done=false;
        try {
            $write=static function (array $record) use ($fp): void {
                $line=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
                if (strlen($line)>16777216 || fwrite($fp,$line)!==strlen($line)) { throw new \RuntimeException('Backup write failed or row too large'); }
            };
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES,true);
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $rows=$pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
            $tables=[];
            foreach ($rows as $row) {
                if (($row[1] ?? '') !== 'BASE TABLE') { throw new \RuntimeException('Unsupported database object'); }
                $name=(string)$row[0]; $quoted=self::ident($name);
                $ddl=$pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(\PDO::FETCH_NUM);
                $cols=$pdo->query('SHOW COLUMNS FROM '.$quoted)->fetchAll(\PDO::FETCH_ASSOC);
                if (!$ddl || !$cols) { throw new \RuntimeException('Cannot inspect table'); }
                foreach ($cols as $col) { if (stripos((string)($col['Extra'] ?? ''),'GENERATED') !== false) { throw new \RuntimeException('Generated columns are not supported'); } }
                $tables[]=['name'=>$name,'create'=>(string)$ddl[1],'columns'=>array_map(static fn(array $c): string => (string)$c['Field'],$cols)];
            }
            if ($tables === []) { throw new \RuntimeException('Empty source database'); }
            $write(['type'=>'header','format'=>'kandoo-inno-v1']);
            foreach ($tables as $table) { $write(['type'=>'table']+$table); }
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            foreach ($tables as $table) {
                $query=$pdo->query('SELECT * FROM '.self::ident($table['name']));
                while (($row=$query->fetch(\PDO::FETCH_NUM)) !== false) {
                    $values=array_map(static fn($v) => $v === null ? null : base64_encode((string)$v),$row);
                    $write(['type'=>'row','table'=>$table['name'],'values'=>$values]);
                }
                $query->closeCursor();
            }
            $write(['type'=>'end','tables'=>count($tables)]);
            $pdo->commit();
            if (!fflush($fp)) { throw new \RuntimeException('Backup flush failed'); }
            fclose($fp); $fp=null;
            $sha=hash_file('sha256',$file); if (!is_string($sha)) { throw new \RuntimeException('Backup checksum failed'); }
            $done=true; return ['path'=>$file,'sha256'=>$sha];
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
            if (is_resource($fp)) { fclose($fp); }
            if (!$done) { @unlink($file); }
        }
    }

    /** Only restore a trusted, checksummed archive into an existing empty disposable test database. */
    public static function restoreTest(\PDO $pdo, string $file, string $expectedSha): void
    {
        $name=$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($name) || preg_match('/^TEST_RESTORE_[a-f0-9]{12}$/D',$name)!==1) { throw new \RuntimeException('Restore requires an isolated TEST_RESTORE database'); }
        if (preg_match('/^[a-f0-9]{64}$/D',$expectedSha)!==1 || !is_file($file) || is_link($file)) { throw new \RuntimeException('Backup integrity mismatch'); }
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?'); $q->execute([$name]);
        if ((int)$q->fetchColumn() !== 0) { throw new \RuntimeException('Restore target must be empty'); }
        $fp=fopen($file,'rb'); if ($fp===false || !flock($fp,LOCK_SH)) { throw new \RuntimeException('Cannot read backup'); }
        $ctx=hash_init('sha256'); hash_update_stream($ctx,$fp);
        if (!hash_equals($expectedSha,hash_final($ctx)) || !rewind($fp)) { fclose($fp); throw new \RuntimeException('Backup integrity mismatch'); }
        $tables=[]; $ended=false; $phase='header';
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            while (($line=fgets($fp,16777218)) !== false) {
                if (strlen($line)>16777216 || !str_ends_with($line,"\n")) { throw new \RuntimeException('Malformed archive line'); }
                $r=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($r) || !is_string($r['type'] ?? null) || $ended) { throw new \RuntimeException('Malformed archive'); }
                if ($phase==='header') {
                    if ($r !== ['type'=>'header','format'=>'kandoo-inno-v1']) { throw new \RuntimeException('Unexpected archive format'); }
                    $phase='tables'; continue;
                }
                if ($r['type']==='table' && $phase==='tables') {
                    $table=$r['name'] ?? null; $ddl=$r['create'] ?? null; $columns=$r['columns'] ?? null;
                    if (!is_string($table) || isset($tables[$table]) || !is_string($ddl) || !is_array($columns) || $columns===[] || !array_is_list($columns)
                        || str_contains($ddl,';') || !str_starts_with($ddl,'CREATE TABLE '.self::ident($table).' (')) { throw new \RuntimeException('Invalid table definition'); }
                    $cols=[]; foreach ($columns as $column) { if (!is_string($column)) { throw new \RuntimeException('Invalid column'); } $cols[]=self::ident($column); }
                    $pdo->exec($ddl);
                    $tables[$table]=['count'=>count($cols),'insert'=>$pdo->prepare('INSERT INTO '.self::ident($table).' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')];
                    continue;
                }
                if ($r['type']==='row' && in_array($phase,['tables','rows'],true)) {
                    $phase='rows'; $table=$r['table'] ?? null; $values=$r['values'] ?? null;
                    if (!is_string($table) || !isset($tables[$table]) || !is_array($values) || !array_is_list($values) || count($values)!==$tables[$table]['count']) { throw new \RuntimeException('Invalid row'); }
                    $decoded=[]; foreach ($values as $value) {
                        if ($value === null) { $decoded[]=null; continue; }
                        if (!is_string($value) || ($decodedValue=base64_decode($value,true))===false) { throw new \RuntimeException('Invalid row encoding'); }
                        $decoded[]=$decodedValue;
                    }
                    $tables[$table]['insert']->execute($decoded); continue;
                }
                if ($r['type']==='end' && count($tables)>0 && ($r['tables'] ?? null)===count($tables)) { $ended=true; continue; }
                throw new \RuntimeException('Invalid archive sequence');
            }
            if (!$ended) { throw new \RuntimeException('Incomplete archive'); }
        } finally { fclose($fp); $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
    }
}
