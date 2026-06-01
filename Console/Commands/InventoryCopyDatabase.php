<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryCopyDatabase extends Command
{
    protected $signature = 'inventory:copy-database
        {--fresh : Drop existing target inventory tables before copying}
        {--skip-migrations : Do not copy inventory-related rows from the migrations table}
        {--dry-run : Show what would be copied without making changes}';

    protected $description = 'Copy Inventory tables and data from the current app database into the dedicated Inventory database';

    public function handle(): int
    {
        $sourceConnection = InventoryDatabase::sourceConnectionName();
        $targetConnection = InventoryDatabase::connectionName();

        $source = DB::connection($sourceConnection);
        $target = DB::connection($targetConnection);

        $sourceDb = (string) $source->getDatabaseName();
        $targetDb = (string) $target->getDatabaseName();

        if ($sourceDb === '' || $targetDb === '') {
            throw new RuntimeException('Source or target database name is empty.');
        }

        if ($sourceConnection === $targetConnection || $sourceDb === $targetDb) {
            $this->error('Source and target Inventory databases must be different.');
            return self::FAILURE;
        }

        $tables = $this->discoverInventoryTables($sourceConnection);

        if (empty($tables)) {
            $this->warn('No Inventory tables found in the source database.');
            return self::SUCCESS;
        }

        $this->info('Source connection: ' . $sourceConnection . ' (' . $sourceDb . ')');
        $this->info('Target connection: ' . $targetConnection . ' (' . $targetDb . ')');
        $this->line('Tables: ' . implode(', ', $tables));

        if ($this->option('dry-run')) {
            if (!$this->option('skip-migrations')) {
                $migrationCount = $this->countInventoryMigrations($sourceConnection);
                $this->line('Inventory migration rows to copy: ' . $migrationCount);
            }

            return self::SUCCESS;
        }

        DB::connection($targetConnection)->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $this->copyTable($sourceConnection, $targetConnection, $sourceDb, $targetDb, $table, (bool) $this->option('fresh'));
            }

            if (!$this->option('skip-migrations')) {
                $this->copyMigrations($sourceConnection, $targetConnection);
            }
        } finally {
            DB::connection($targetConnection)->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('Inventory database copy complete.');

        return self::SUCCESS;
    }

    private function discoverInventoryTables(string $sourceConnection): array
    {
        $rows = DB::connection($sourceConnection)->select('SHOW TABLES');
        if (empty($rows)) {
            return [];
        }

        $tables = [];
        foreach ($rows as $row) {
            $table = (string) array_values((array) $row)[0];
            if (str_starts_with($table, 'inventory_')) {
                $tables[] = $table;
            }
        }

        sort($tables);

        return $tables;
    }

    private function copyTable(
        string $sourceConnection,
        string $targetConnection,
        string $sourceDb,
        string $targetDb,
        string $table,
        bool $fresh
    ): void {
        $this->line('Copying table: ' . $table);

        $targetTableExists = $this->tableExists($targetConnection, $table);

        if ($targetTableExists && $fresh) {
            DB::connection($targetConnection)->statement('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            $targetTableExists = false;
        }

        if (!$targetTableExists) {
            $createRow = DB::connection($sourceConnection)->selectOne('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
            $createSql = (string) ((array) $createRow)['Create Table'];
            DB::connection($targetConnection)->statement($createSql);
        } else {
            DB::connection($targetConnection)->statement('SET FOREIGN_KEY_CHECKS=0');
            DB::connection($targetConnection)->statement('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
            DB::connection($targetConnection)->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::connection($targetConnection)->statement(
            'INSERT INTO `' . str_replace('`', '``', $targetDb) . '`.`' . str_replace('`', '``', $table) . '` ' .
            'SELECT * FROM `' . str_replace('`', '``', $sourceDb) . '`.`' . str_replace('`', '``', $table) . '`'
        );
    }

    private function copyMigrations(string $sourceConnection, string $targetConnection): void
    {
        $rows = DB::connection($sourceConnection)
            ->table('migrations')
            ->where('migration', 'like', '%inventory%')
            ->orderBy('batch')
            ->orderBy('migration')
            ->get()
            ->map(fn ($row) => ['migration' => $row->migration, 'batch' => $row->batch])
            ->all();

        if (empty($rows)) {
            return;
        }

        if (!$this->tableExists($targetConnection, 'migrations')) {
            DB::connection($targetConnection)->statement(
                'CREATE TABLE `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT, `migration` varchar(255) NOT NULL, `batch` int NOT NULL, PRIMARY KEY (`id`))'
            );
        }

        DB::connection($targetConnection)->table('migrations')->where('migration', 'like', '%inventory%')->delete();
        DB::connection($targetConnection)->table('migrations')->insert($rows);
    }

    private function countInventoryMigrations(string $sourceConnection): int
    {
        return DB::connection($sourceConnection)
            ->table('migrations')
            ->where('migration', 'like', '%inventory%')
            ->count();
    }

    private function tableExists(string $connection, string $table): bool
    {
        return !empty(DB::connection($connection)->select("SHOW TABLES LIKE '" . str_replace("'", "\\'", $table) . "'"));
    }
}
