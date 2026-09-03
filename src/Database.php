<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог для БД: ' . $dir);
        }

        $this->pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS users (
                portal TEXT NOT NULL,
                bitrix_id INTEGER NOT NULL,
                email TEXT,
                login TEXT,
                first_name TEXT,
                last_name TEXT,
                second_name TEXT,
                work_position TEXT,
                active INTEGER NOT NULL DEFAULT 1,
                raw_json TEXT,
                synced_at TEXT NOT NULL,
                PRIMARY KEY (portal, bitrix_id)
            );

            CREATE TABLE IF NOT EXISTS user_mappings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cloud_user_id INTEGER NOT NULL UNIQUE,
                box_user_id INTEGER NOT NULL,
                match_type TEXT NOT NULL DEFAULT 'manual',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            -- ===== НОВЫЕ ТАБЛИЦЫ ДЛЯ КОМПАНИЙ =====
            CREATE TABLE IF NOT EXISTS company_import (
                portal TEXT NOT NULL,
                bitrix_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                assigned_by_id INTEGER,
                company_type TEXT,
                industry TEXT,
                phone TEXT,
                email TEXT,
                website TEXT,
                address TEXT,
                raw_json TEXT,
                synced_at TEXT NOT NULL,
                PRIMARY KEY (portal, bitrix_id)
            );

            CREATE TABLE IF NOT EXISTS company_mappings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cloud_company_id INTEGER NOT NULL UNIQUE,
                box_company_id INTEGER NOT NULL UNIQUE,
                title TEXT NOT NULL,
                assigned_by_cloud_id INTEGER,
                assigned_by_box_id INTEGER,
                match_type TEXT NOT NULL DEFAULT 'manual',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            -- ===== ТАБЛИЦЫ ДЛЯ КОНТАКТОВ =====
            CREATE TABLE IF NOT EXISTS contact_import (
                portal TEXT NOT NULL,
                bitrix_id INTEGER NOT NULL,
                first_name TEXT,
                last_name TEXT,
                second_name TEXT,
                full_name TEXT NOT NULL,
                email TEXT,
                phone TEXT,
                company_id INTEGER,
                assigned_by_id INTEGER,
                raw_json TEXT,
                synced_at TEXT NOT NULL,
                PRIMARY KEY (portal, bitrix_id)
            );

            CREATE TABLE IF NOT EXISTS contact_mappings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cloud_contact_id INTEGER NOT NULL UNIQUE,
                box_contact_id INTEGER NOT NULL UNIQUE,
                full_name TEXT NOT NULL,
                cloud_company_id INTEGER,
                box_company_id INTEGER,
                assigned_by_cloud_id INTEGER,
                assigned_by_box_id INTEGER,
                match_type TEXT NOT NULL DEFAULT 'manual',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            -- ===== ТАБЛИЦЫ ДЛЯ СТАДИЙ И ЛИДОВ =====
            CREATE TABLE IF NOT EXISTS stage_import (
                portal TEXT NOT NULL,
                category_id INTEGER NOT NULL DEFAULT 0,
                status_id TEXT NOT NULL,
                name TEXT NOT NULL,
                sort INTEGER DEFAULT 0,
                is_final INTEGER DEFAULT 0,
                raw_json TEXT,
                synced_at TEXT NOT NULL,
                PRIMARY KEY (portal, category_id, status_id)
            );

            CREATE TABLE IF NOT EXISTS stage_mappings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cloud_category_id INTEGER NOT NULL,
                cloud_status_id TEXT NOT NULL,
                box_category_id INTEGER NOT NULL,
                box_status_id TEXT NOT NULL,
                name TEXT NOT NULL,
                match_type TEXT NOT NULL DEFAULT 'auto',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(cloud_category_id, cloud_status_id)
            );

            CREATE TABLE IF NOT EXISTS lead_import (
                portal TEXT NOT NULL,
                bitrix_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                category_id INTEGER DEFAULT 0,
                status_id TEXT,
                assigned_by_id INTEGER,
                company_id INTEGER,
                contact_id INTEGER,
                opportunity INTEGER,
                currency TEXT,
                source_id TEXT,
                stage_id INTEGER,
                raw_json TEXT,
                synced_at TEXT NOT NULL,
                PRIMARY KEY (portal, bitrix_id)
            );

            CREATE TABLE IF NOT EXISTS lead_mappings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cloud_lead_id INTEGER NOT NULL UNIQUE,
                box_lead_id INTEGER NOT NULL UNIQUE,
                title TEXT NOT NULL,
                cloud_category_id INTEGER,
                cloud_status_id TEXT,
                box_category_id INTEGER,
                box_status_id TEXT,
                cloud_company_id INTEGER,
                box_company_id INTEGER,
                cloud_contact_id INTEGER,
                box_contact_id INTEGER,
                assigned_by_cloud_id INTEGER,
                assigned_by_box_id INTEGER,
                match_type TEXT NOT NULL DEFAULT 'manual',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_users_email ON users (portal, email);
            CREATE INDEX IF NOT EXISTS idx_mappings_box ON user_mappings (box_user_id);
            CREATE INDEX IF NOT EXISTS idx_company_import_title ON company_import (portal, title);
            CREATE INDEX IF NOT EXISTS idx_company_mappings_box ON company_mappings (box_company_id);
            CREATE INDEX IF NOT EXISTS idx_contact_import_name ON contact_import (portal, full_name);
            CREATE INDEX IF NOT EXISTS idx_contact_import_company ON contact_import (portal, company_id);
            CREATE INDEX IF NOT EXISTS idx_contact_mappings_box ON contact_mappings (box_contact_id);
            CREATE INDEX IF NOT EXISTS idx_lead_import_status ON lead_import (portal, category_id, status_id);
            CREATE INDEX IF NOT EXISTS idx_lead_import_company ON lead_import (portal, company_id);
            CREATE INDEX IF NOT EXISTS idx_lead_import_contact ON lead_import (portal, contact_id);
            CREATE INDEX IF NOT EXISTS idx_lead_mappings_box ON lead_mappings (box_lead_id);
            SQL
        );
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}