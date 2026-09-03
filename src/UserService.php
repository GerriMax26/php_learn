<?php

declare(strict_types=1);

final class UserService
{


    public function __construct(private Database $db)
    {
    }

    public function isConfigured(): bool
    {
        if ($this->db->getSetting('demo_mode') === '1') {
            return true;
        }

        return $this->webhook('cloud') !== '' && $this->webhook('box') !== '';
    }

    public function webhook(string $portal): string
{
    // Сначала проверяем .env
    $envKey = strtoupper($portal) . '_WEBHOOK';
    $webhook = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? null;

    if ($webhook) {
        return trim($webhook);
    }

    // Fallback: читаем из БД
    return trim((string) $this->db->getSetting($portal . '_webhook', ''));
}

    public function demoMode(): bool
    {
        return $this->db->getSetting('demo_mode') === '1';
    }

    public function saveSettings(string $cloudWebhook, string $boxWebhook, bool $demoMode): void
    {
        $this->db->setSetting('cloud_webhook', trim($cloudWebhook));
        $this->db->setSetting('box_webhook', trim($boxWebhook));
        $this->db->setSetting('demo_mode', $demoMode ? '1' : '0');
    }

    /**
     * @return array{cloud: int, box: int}
     */
    public function syncUsers(): array
    {
        if ($this->demoMode()) {
            $this->replacePortalUsers('cloud', DemoUsers::cloud());
            $this->replacePortalUsers('box', DemoUsers::box());
            $this->autoMatchByEmail();

            return [
                'cloud' => count(DemoUsers::cloud()),
                'box' => count(DemoUsers::box()),
            ];
        }

        $cloud = (new BitrixClient($this->webhook('cloud')))->getUsers();
        $box = (new BitrixClient($this->webhook('box')))->getUsers();

        $this->replacePortalUsers('cloud', $cloud);
        $this->replacePortalUsers('box', $box);
        $this->autoMatchByEmail();

        return [
            'cloud' => count($cloud),
            'box' => count($box),
        ];
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function replacePortalUsers(string $portal, array $users): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM users WHERE portal = :portal');
            $delete->execute(['portal' => $portal]);

            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO users (
                    portal, bitrix_id, email, login, first_name, last_name, second_name,
                    work_position, active, raw_json, synced_at
                ) VALUES (
                    :portal, :bitrix_id, :email, :login, :first_name, :last_name, :second_name,
                    :work_position, :active, :raw_json, :synced_at
                )
                SQL
            );

            $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            foreach ($users as $user) {
                $insert->execute([
                    'portal' => $portal,
                    'bitrix_id' => (int) $user['ID'],
                    'email' => $this->normalizeEmail((string) ($user['EMAIL'] ?? '')),
                    'login' => (string) ($user['LOGIN'] ?? ''),
                    'first_name' => (string) ($user['NAME'] ?? ''),
                    'last_name' => (string) ($user['LAST_NAME'] ?? ''),
                    'second_name' => (string) ($user['SECOND_NAME'] ?? ''),
                    'work_position' => (string) ($user['WORK_POSITION'] ?? ''),
                    'active' => (($user['ACTIVE'] ?? 'Y') === 'Y') ? 1 : 0,
                    'raw_json' => json_encode($user, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'synced_at' => $now,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function autoMatchByEmail(): int
    {
        $pdo = $this->db->pdo();
        $cloudUsers = $this->listUsers('cloud');
        $boxByEmail = [];
        foreach ($this->listUsers('box') as $user) {
            $email = $user['email'] ?? '';
            if ($email === '') {
                continue;
            }
            $boxByEmail[$email] ??= [];
            $boxByEmail[$email][] = $user;
        }

        $mappedCloud = $this->mappedCloudIds();
        $mappedBox = $this->mappedBoxIds();
        $created = 0;

        $insert = $pdo->prepare(
            <<<'SQL'
            INSERT INTO user_mappings (cloud_user_id, box_user_id, match_type, created_at, updated_at)
            VALUES (:cloud_user_id, :box_user_id, 'auto_email', :created_at, :updated_at)
            SQL
        );

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        foreach ($cloudUsers as $cloud) {
            $cloudId = (int) $cloud['bitrix_id'];
            if (isset($mappedCloud[$cloudId])) {
                continue;
            }

            $email = $cloud['email'] ?? '';
            $candidates = $boxByEmail[$email] ?? [];
            if (count($candidates) !== 1) {
                continue;
            }

            $boxId = (int) $candidates[0]['bitrix_id'];
            if (isset($mappedBox[$boxId])) {
                continue;
            }

            $insert->execute([
                'cloud_user_id' => $cloudId,
                'box_user_id' => $boxId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $mappedCloud[$cloudId] = $boxId;
            $mappedBox[$boxId] = $cloudId;
            $created++;
        }

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsers(string $portal): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM users WHERE portal = :portal
             ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE, bitrix_id'
        );
        $stmt->execute(['portal' => $portal]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mappingsByCloudId(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT cloud_user_id, box_user_id, match_type FROM user_mappings'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['cloud_user_id']] = $row;
        }

        return $map;
    }

    /**
     * @param list<array{cloud_user_id: int, box_user_id: int|null}> $pairs
     */
    public function saveMappings(array $pairs): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM user_mappings');
            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO user_mappings (cloud_user_id, box_user_id, match_type, created_at, updated_at)
                VALUES (:cloud_user_id, :box_user_id, :match_type, :created_at, :updated_at)
                SQL
            );
            $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $usedBox = [];

            foreach ($pairs as $pair) {
                $cloudId = (int) $pair['cloud_user_id'];
                $boxId = $pair['box_user_id'];
                if ($boxId === null || $boxId === 0) {
                    continue;
                }
                $boxId = (int) $boxId;
                if (isset($usedBox[$boxId])) {
                    throw new InvalidArgumentException(
                        'Пользователь коробки #' . $boxId . ' сопоставлен дважды'
                    );
                }
                $usedBox[$boxId] = true;
                $insert->execute([
                    'cloud_user_id' => $cloudId,
                    'box_user_id' => $boxId,
                    'match_type' => 'manual',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<int, int>
     */
    private function mappedCloudIds(): array
    {
        $rows = $this->db->pdo()->query('SELECT cloud_user_id, box_user_id FROM user_mappings')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['cloud_user_id']] = (int) $row['box_user_id'];
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function mappedBoxIds(): array
    {
        $rows = $this->db->pdo()->query('SELECT cloud_user_id, box_user_id FROM user_mappings')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['box_user_id']] = (int) $row['cloud_user_id'];
        }

        return $map;
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function displayName(array $user): string
    {
        $parts = array_filter([
            trim((string) ($user['last_name'] ?? '')),
            trim((string) ($user['first_name'] ?? '')),
            trim((string) ($user['second_name'] ?? '')),
        ]);

        $name = trim(implode(' ', $parts));
        if ($name === '') {
            $name = (string) ($user['login'] ?? 'Без имени');
        }

        return $name;
    }
}
