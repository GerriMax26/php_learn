<?php

declare(strict_types=1);

final class ContactService
{
    public function __construct(
        private Database $db,
        private UserService $userService,
        private CompanyService $companyService,
    ) {
    }

    /**
     * Загрузить контакты с обоих порталов в БД
     * 
     * @return array{cloud: int, box: int}
     */
    public function syncContacts(): array
    {
        $cloud = (new BitrixClient($this->userService->webhook('cloud')))->getContacts();
        $box = (new BitrixClient($this->userService->webhook('box')))->getContacts();

        $this->replacePortalContacts('cloud', $cloud);
        $this->replacePortalContacts('box', $box);

        return [
            'cloud' => count($cloud),
            'box' => count($box),
        ];
    }

    /**
     * Перенести контакты из облака в коробку
     * 
     * @return array{created: int, skipped: int, errors: int, details: array<int, string>}
     */
    public function migrateContacts(): array
    {
        $cloudContacts = $this->listContacts('cloud');
        $result = [
            'created' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        $userMappings = $this->userService->mappingsByCloudId();
        $companyMappings = $this->companyService->getAllMappings();

        foreach ($cloudContacts as $cloud) {
            $cloudId = (int) $cloud['bitrix_id'];

            // Проверяем, не перенесён ли уже контакт
            $existing = $this->getMappingByCloudId($cloudId);
            if ($existing && $existing['box_contact_id']) {
                $result['skipped']++;
                $result['details'][] = "Контакт #{$cloudId} уже перенесён";
                continue;
            }

            // Определяем компанию в коробке
            $cloudCompanyId = (int) ($cloud['company_id'] ?? 0);
            $boxCompanyId = $companyMappings[$cloudCompanyId]['box_company_id'] ?? null;

            // Определяем ответственного в коробке
            $assignedCloudId = (int) ($cloud['assigned_by_id'] ?? 0);
            $assignedBoxId = $userMappings[$assignedCloudId]['box_user_id'] ?? null;

            try {
                $boxId = $this->createContactInBox($cloud, $boxCompanyId, $assignedBoxId);

                $this->saveMapping(
                    $cloudId,
                    $boxId,
                    (string) ($cloud['full_name'] ?? 'Без имени'),
                    $cloudCompanyId,
                    $boxCompanyId,
                    $assignedCloudId,
                    $assignedBoxId
                );
                $result['created']++;
                $result['details'][] = "Контакт #{$cloudId} → #{$boxId}";
            } catch (Throwable $e) {
                $result['errors']++;
                $result['details'][] = "Ошибка при переносе контакта #{$cloudId}: " . $e->getMessage();
                error_log("Ошибка переноса контакта #{$cloudId}: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Создать контакт в коробке
     */
    private function createContactInBox(array $cloudContact, ?int $boxCompanyId, ?int $assignedBoxId): int
    {
        $fields = [
            'NAME' => (string) ($cloudContact['first_name'] ?? ''),
            'LAST_NAME' => (string) ($cloudContact['last_name'] ?? ''),
            'SECOND_NAME' => (string) ($cloudContact['second_name'] ?? ''),
        ];

        if ($boxCompanyId > 0) {
            $fields['COMPANY_ID'] = $boxCompanyId;
        }

        if ($assignedBoxId > 0) {
            $fields['ASSIGNED_BY_ID'] = $assignedBoxId;
        }

        // Добавляем email
        if (!empty($cloudContact['email'])) {
            $fields['EMAIL'] = [
                ['VALUE' => (string) $cloudContact['email'], 'VALUE_TYPE' => 'WORK']
            ];
        }

        // Добавляем телефон
        if (!empty($cloudContact['phone'])) {
            $fields['PHONE'] = [
                ['VALUE' => (string) $cloudContact['phone'], 'VALUE_TYPE' => 'WORK']
            ];
        }

        $client = new BitrixClient($this->userService->webhook('box'));
        return $client->addContact($fields);
    }

    /**
     * Сохранить сопоставление контактов
     */
    private function saveMapping(
        int $cloudId,
        int $boxId,
        string $fullName,
        int $cloudCompanyId,
        ?int $boxCompanyId,
        int $assignedCloudId,
        ?int $assignedBoxId
    ): void {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO contact_mappings 
            (cloud_contact_id, box_contact_id, full_name, cloud_company_id, box_company_id, 
             assigned_by_cloud_id, assigned_by_box_id, match_type, created_at, updated_at)
            VALUES (:cloud_id, :box_id, :full_name, :cloud_company, :box_company, 
                    :assigned_cloud, :assigned_box, :match_type, :created_at, :updated_at)'
        );

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $stmt->execute([
            'cloud_id' => $cloudId,
            'box_id' => $boxId,
            'full_name' => $fullName,
            'cloud_company' => $cloudCompanyId ?: null,
            'box_company' => $boxCompanyId,
            'assigned_cloud' => $assignedCloudId ?: null,
            'assigned_box' => $assignedBoxId,
            'match_type' => 'auto',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Получить сопоставление по ID контакта в облаке
     * 
     * @return array<string, mixed>|null
     */
    public function getMappingByCloudId(int $cloudId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM contact_mappings WHERE cloud_contact_id = :cloud_id'
        );
        $stmt->execute(['cloud_id' => $cloudId]);
        $row = $stmt->fetch();
        
        return $row === false ? null : $row;
    }

    /**
     * Получить все сопоставления контактов
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllMappings(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT * FROM contact_mappings ORDER BY cloud_contact_id'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['cloud_contact_id']] = $row;
        }

        return $map;
    }

    /**
     * Получить список контактов для портала
     * 
     * @return list<array<string, mixed>>
     */
    public function listContacts(string $portal): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM contact_import WHERE portal = :portal
             ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE, bitrix_id'
        );
        $stmt->execute(['portal' => $portal]);

        return $stmt->fetchAll();
    }

    /**
     * Заменить контакты в БД (полная перезапись)
     * 
     * @param list<array<string, mixed>> $contacts
     */
    private function replacePortalContacts(string $portal, array $contacts): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM contact_import WHERE portal = :portal');
            $delete->execute(['portal' => $portal]);

            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO contact_import (
                    portal, bitrix_id, first_name, last_name, second_name, full_name,
                    email, phone, company_id, assigned_by_id, raw_json, synced_at
                ) VALUES (
                    :portal, :bitrix_id, :first_name, :last_name, :second_name, :full_name,
                    :email, :phone, :company_id, :assigned_by_id, :raw_json, :synced_at
                )
                SQL
            );

            $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            foreach ($contacts as $contact) {
                $firstName = (string) ($contact['NAME'] ?? '');
                $lastName = (string) ($contact['LAST_NAME'] ?? '');
                $secondName = (string) ($contact['SECOND_NAME'] ?? '');
                $fullName = trim($lastName . ' ' . $firstName . ' ' . $secondName);
                if ($fullName === '') {
                    $fullName = 'Контакт #' . $contact['ID'];
                }

                $insert->execute([
                    'portal' => $portal,
                    'bitrix_id' => (int) $contact['ID'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'second_name' => $secondName,
                    'full_name' => $fullName,
                    'email' => is_array($contact['EMAIL'] ?? null) ? (string) ($contact['EMAIL'][0]['VALUE'] ?? '') : '',
                    'phone' => is_array($contact['PHONE'] ?? null) ? (string) ($contact['PHONE'][0]['VALUE'] ?? '') : '',
                    'company_id' => isset($contact['COMPANY_ID']) ? (int) $contact['COMPANY_ID'] : null,
                    'assigned_by_id' => isset($contact['ASSIGNED_BY_ID']) ? (int) $contact['ASSIGNED_BY_ID'] : null,
                    'raw_json' => json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
     * Удалить все сопоставления контактов
     */
    public function clearMappings(): void
    {
        $this->db->pdo()->exec('DELETE FROM contact_mappings');
    }

    /**
     * Получить контакт по ID из кеша
     * 
     * @return array<string, mixed>|null
     */
    public function getContactById(string $portal, int $bitrixId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM contact_import WHERE portal = :portal AND bitrix_id = :bitrix_id'
        );
        $stmt->execute(['portal' => $portal, 'bitrix_id' => $bitrixId]);
        $row = $stmt->fetch();
        
        return $row === false ? null : $row;
    }
}