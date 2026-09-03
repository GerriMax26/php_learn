<?php

declare(strict_types=1);

final class CompanyService
{
    public function __construct(
        private Database $db,
        private UserService $userService,
    ) {
    }

    /**
     * Загрузить компании с обоих порталов в БД
     * 
     * @return array{cloud: int, box: int}
     */
    public function syncCompanies(): array
    {
        // Всегда загружаем с реальных порталов
        $cloud = (new BitrixClient($this->userService->webhook('cloud')))->getCompanies();
        $box = (new BitrixClient($this->userService->webhook('box')))->getCompanies();

        $this->replacePortalCompanies('cloud', $cloud);
        $this->replacePortalCompanies('box', $box);

        return [
            'cloud' => count($cloud),
            'box' => count($box),
        ];
    }

    /**
     * Перенести компании из облака в коробку
     * 
     * @return array{created: int, skipped: int, errors: int, details: array<int, string>}
     */
    public function migrateCompanies(): array
    {
        $pdo = $this->db->pdo();
        $cloudCompanies = $this->listCompanies('cloud');
        $result = [
            'created' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        // Получаем сопоставления пользователей (облако → коробка)
        $userMappings = $this->userService->mappingsByCloudId();

        foreach ($cloudCompanies as $cloud) {
            $cloudId = (int) $cloud['bitrix_id'];

            // Проверяем, не перенесена ли уже компания
            $existing = $this->getMappingByCloudId($cloudId);
            if ($existing && $existing['box_company_id']) {
                $result['skipped']++;
                $result['details'][] = "Компания #{$cloudId} уже перенесена (ID коробки: {$existing['box_company_id']})";
                continue;
            }

            // Определяем ответственного в коробке
            $assignedByCloudId = (int) ($cloud['assigned_by_id'] ?? 0);
            $assignedByBoxId = $userMappings[$assignedByCloudId]['box_user_id'] ?? null;

            try {
                // Создаём компанию в коробке
                $boxId = $this->createCompanyInBox($cloud, $assignedByBoxId);

                // Сохраняем сопоставление
                $this->saveMapping(
                    $cloudId,
                    $boxId,
                    (string) $cloud['title'],
                    $assignedByCloudId,
                    $assignedByBoxId
                );
                $result['created']++;
                $result['details'][] = "Компания #{$cloudId} → #{$boxId} (ответственный: " . ($assignedByBoxId ? $assignedByBoxId : 'не назначен') . ")";
            } catch (Throwable $e) {
                $result['errors']++;
                $result['details'][] = "Ошибка при переносе компании #{$cloudId}: " . $e->getMessage();
                // Логируем ошибку
                error_log("Ошибка переноса компании #{$cloudId}: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Создать компанию в коробке
     * 
     * @param array<string, mixed> $cloudCompany
     */
    private function createCompanyInBox(array $cloudCompany, ?int $assignedByBoxId): int
    {
        $fields = [
            'TITLE' => (string) ($cloudCompany['title'] ?? 'Без названия'),
            'COMPANY_TYPE' => (string) ($cloudCompany['company_type'] ?? ''),
            'INDUSTRY' => (string) ($cloudCompany['industry'] ?? ''),
        ];

        // Если есть ответственный в коробке — проставляем
        if ($assignedByBoxId > 0) {
            $fields['ASSIGNED_BY_ID'] = $assignedByBoxId;
        }

        // Добавляем телефоны
        if (!empty($cloudCompany['phone'])) {
            $fields['PHONE'] = [
                ['VALUE' => (string) $cloudCompany['phone'], 'VALUE_TYPE' => 'WORK']
            ];
        }

        // Добавляем email
        if (!empty($cloudCompany['email'])) {
            $fields['EMAIL'] = [
                ['VALUE' => (string) $cloudCompany['email'], 'VALUE_TYPE' => 'WORK']
            ];
        }

        // Добавляем веб-сайт
        if (!empty($cloudCompany['website'])) {
            $fields['WEB'] = [
                ['VALUE' => (string) $cloudCompany['website'], 'VALUE_TYPE' => 'WORK']
            ];
        }

        // Добавляем адрес
        if (!empty($cloudCompany['address'])) {
            $fields['ADDRESS'] = (string) $cloudCompany['address'];
        }

        $client = new BitrixClient($this->userService->webhook('box'));
        return $client->addCompany($fields);
    }

    /**
     * Сохранить сопоставление компаний
     */
    private function saveMapping(
        int $cloudId,
        int $boxId,
        string $title,
        int $assignedByCloudId,
        ?int $assignedByBoxId
    ): void {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO company_mappings 
            (cloud_company_id, box_company_id, title, assigned_by_cloud_id, assigned_by_box_id, match_type, created_at, updated_at)
            VALUES (:cloud_id, :box_id, :title, :assigned_cloud, :assigned_box, :match_type, :created_at, :updated_at)'
        );

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $stmt->execute([
            'cloud_id' => $cloudId,
            'box_id' => $boxId,
            'title' => $title,
            'assigned_cloud' => $assignedByCloudId,
            'assigned_box' => $assignedByBoxId,
            'match_type' => 'auto',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Получить сопоставление по ID компании в облаке
     * 
     * @return array<string, mixed>|null
     */
    public function getMappingByCloudId(int $cloudId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM company_mappings WHERE cloud_company_id = :cloud_id'
        );
        $stmt->execute(['cloud_id' => $cloudId]);
        $row = $stmt->fetch();
        
        return $row === false ? null : $row;
    }

    /**
     * Получить все сопоставления компаний
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllMappings(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT * FROM company_mappings ORDER BY cloud_company_id'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['cloud_company_id']] = $row;
        }

        return $map;
    }

    /**
     * Получить список компаний для портала
     * 
     * @return list<array<string, mixed>>
     */
    public function listCompanies(string $portal): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM company_import WHERE portal = :portal
             ORDER BY title COLLATE NOCASE, bitrix_id'
        );
        $stmt->execute(['portal' => $portal]);

        return $stmt->fetchAll();
    }

    /**
     * Заменить компании в БД (полная перезапись)
     * 
     * @param list<array<string, mixed>> $companies
     */
    private function replacePortalCompanies(string $portal, array $companies): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM company_import WHERE portal = :portal');
            $delete->execute(['portal' => $portal]);

            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO company_import (
                    portal, bitrix_id, title, assigned_by_id, company_type, industry,
                    phone, email, website, address, raw_json, synced_at
                ) VALUES (
                    :portal, :bitrix_id, :title, :assigned_by_id, :company_type, :industry,
                    :phone, :email, :website, :address, :raw_json, :synced_at
                )
                SQL
            );

            $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            foreach ($companies as $company) {
                $insert->execute([
                    'portal' => $portal,
                    'bitrix_id' => (int) $company['ID'],
                    'title' => (string) ($company['TITLE'] ?? 'Без названия'),
                    'assigned_by_id' => isset($company['ASSIGNED_BY_ID']) ? (int) $company['ASSIGNED_BY_ID'] : null,
                    'company_type' => (string) ($company['COMPANY_TYPE'] ?? ''),
                    'industry' => (string) ($company['INDUSTRY'] ?? ''),
                    'phone' => is_array($company['PHONE'] ?? null) ? (string) ($company['PHONE'][0]['VALUE'] ?? '') : '',
                    'email' => is_array($company['EMAIL'] ?? null) ? (string) ($company['EMAIL'][0]['VALUE'] ?? '') : '',
                    'website' => is_array($company['WEB'] ?? null) ? (string) ($company['WEB'][0]['VALUE'] ?? '') : '',
                    'address' => (string) ($company['ADDRESS'] ?? ''),
                    'raw_json' => json_encode($company, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'synced_at' => $now,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Удалить все сопоставления компаний
     */
    public function clearMappings(): void
    {
        $this->db->pdo()->exec('DELETE FROM company_mappings');
    }

    /**
     * Получить компанию по ID из кеша
     * 
     * @return array<string, mixed>|null
     */
    public function getCompanyById(string $portal, int $bitrixId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM company_import WHERE portal = :portal AND bitrix_id = :bitrix_id'
        );
        $stmt->execute(['portal' => $portal, 'bitrix_id' => $bitrixId]);
        $row = $stmt->fetch();
        
        return $row === false ? null : $row;
    }
}