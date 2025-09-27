<?php
/**
 * Database Migration Manager
 * Handles database schema migrations for OneBookNav Enhanced
 */

class MigrationManager {
    private $db;
    private $migrationsPath;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->migrationsPath = __DIR__ . '/../data/migrations/';
        $this->ensureMigrationsTable();
    }

    /**
     * Create migrations tracking table if it doesn't exist
     */
    private function ensureMigrationsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) UNIQUE NOT NULL,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            batch INTEGER NOT NULL
        )";
        $this->db->query($sql);
    }

    /**
     * Run all pending migrations
     */
    public function runMigrations() {
        $appliedMigrations = $this->getAppliedMigrations();
        $availableMigrations = $this->getAvailableMigrations();
        $pendingMigrations = array_diff($availableMigrations, $appliedMigrations);

        if (empty($pendingMigrations)) {
            return ['status' => 'success', 'message' => 'No pending migrations', 'migrations' => []];
        }

        $batch = $this->getNextBatch();
        $results = [];

        foreach ($pendingMigrations as $migration) {
            try {
                $this->runMigration($migration, $batch);
                $results[] = ['migration' => $migration, 'status' => 'success'];
            } catch (Exception $e) {
                $results[] = ['migration' => $migration, 'status' => 'error', 'error' => $e->getMessage()];
                break; // Stop on first error
            }
        }

        return [
            'status' => 'success',
            'message' => 'Migrations completed',
            'migrations' => $results
        ];
    }

    /**
     * Run a specific migration
     */
    private function runMigration($migrationName, $batch) {
        $migrationFile = $this->migrationsPath . $migrationName;

        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: $migrationName");
        }

        $sql = file_get_contents($migrationFile);
        if ($sql === false) {
            throw new Exception("Could not read migration file: $migrationName");
        }

        // Execute the migration
        $this->db->exec($sql);

        // Record the migration as applied
        $this->db->query(
            "INSERT INTO migrations (migration_name, batch) VALUES (?, ?)",
            [$migrationName, $batch]
        );
    }

    /**
     * Get list of applied migrations
     */
    private function getAppliedMigrations() {
        $result = $this->db->query("SELECT migration_name FROM migrations ORDER BY id");
        return array_column($result, 'migration_name');
    }

    /**
     * Get list of available migration files
     */
    private function getAvailableMigrations() {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = scandir($this->migrationsPath);
        $migrations = [];

        foreach ($files as $file) {
            if (preg_match('/^\d{3}_.*\.sql$/', $file)) {
                $migrations[] = $file;
            }
        }

        sort($migrations);
        return $migrations;
    }

    /**
     * Get next batch number
     */
    private function getNextBatch() {
        $result = $this->db->fetchOne("SELECT MAX(batch) as max_batch FROM migrations");
        return ($result['max_batch'] ?? 0) + 1;
    }

    /**
     * Check if database needs migration
     */
    public function needsMigration() {
        $appliedMigrations = $this->getAppliedMigrations();
        $availableMigrations = $this->getAvailableMigrations();
        $pendingMigrations = array_diff($availableMigrations, $appliedMigrations);

        return !empty($pendingMigrations);
    }

    /**
     * Get migration status
     */
    public function getStatus() {
        $appliedMigrations = $this->getAppliedMigrations();
        $availableMigrations = $this->getAvailableMigrations();
        $pendingMigrations = array_diff($availableMigrations, $appliedMigrations);

        return [
            'total_migrations' => count($availableMigrations),
            'applied_migrations' => count($appliedMigrations),
            'pending_migrations' => count($pendingMigrations),
            'needs_migration' => !empty($pendingMigrations),
            'pending_list' => $pendingMigrations
        ];
    }

    /**
     * Rollback last batch of migrations
     */
    public function rollback() {
        $lastBatch = $this->db->fetchOne("SELECT MAX(batch) as max_batch FROM migrations");

        if (!$lastBatch || !$lastBatch['max_batch']) {
            return ['status' => 'success', 'message' => 'No migrations to rollback'];
        }

        $migrationsToRollback = $this->db->query(
            "SELECT migration_name FROM migrations WHERE batch = ? ORDER BY id DESC",
            [$lastBatch['max_batch']]
        );

        $results = [];
        foreach ($migrationsToRollback as $migration) {
            try {
                // Remove migration record
                $this->db->query(
                    "DELETE FROM migrations WHERE migration_name = ?",
                    [$migration['migration_name']]
                );
                $results[] = ['migration' => $migration['migration_name'], 'status' => 'rolled_back'];
            } catch (Exception $e) {
                $results[] = ['migration' => $migration['migration_name'], 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return [
            'status' => 'success',
            'message' => 'Rollback completed',
            'migrations' => $results
        ];
    }

    /**
     * Create a new migration file
     */
    public function createMigration($name, $description = '') {
        $timestamp = date('Y_m_d_His');
        $filename = sprintf('%s_%s.sql', $timestamp, $name);
        $filepath = $this->migrationsPath . $filename;

        $template = "-- Migration: $name\n";
        $template .= "-- Description: $description\n";
        $template .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";
        $template .= "BEGIN TRANSACTION;\n\n";
        $template .= "-- Add your migration SQL here\n\n";
        $template .= "COMMIT;\n";

        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }

        file_put_contents($filepath, $template);

        return [
            'status' => 'success',
            'message' => 'Migration file created',
            'filename' => $filename,
            'path' => $filepath
        ];
    }

    /**
     * Check database health and integrity
     */
    public function checkDatabaseHealth() {
        $issues = [];

        try {
            // Check if all required tables exist
            $requiredTables = [
                'users', 'categories', 'bookmarks', 'settings',
                'invite_codes', 'dead_link_checks', 'click_logs'
            ];

            foreach ($requiredTables as $table) {
                $result = $this->db->fetchOne(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                    [$table]
                );
                if (!$result) {
                    $issues[] = "Missing table: $table";
                }
            }

            // Check for required indexes
            $requiredIndexes = [
                'idx_bookmarks_user_category',
                'idx_categories_user',
                'idx_bookmarks_sort'
            ];

            foreach ($requiredIndexes as $index) {
                $result = $this->db->fetchOne(
                    "SELECT name FROM sqlite_master WHERE type='index' AND name=?",
                    [$index]
                );
                if (!$result) {
                    $issues[] = "Missing index: $index";
                }
            }

            // Check foreign key constraints
            $result = $this->db->query("PRAGMA foreign_key_check");
            if (!empty($result)) {
                $issues[] = "Foreign key constraint violations found";
            }

        } catch (Exception $e) {
            $issues[] = "Database check error: " . $e->getMessage();
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'checked_at' => date('Y-m-d H:i:s')
        ];
    }
}