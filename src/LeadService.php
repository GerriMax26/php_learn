<?php

declare(strict_types=1);

final class LeadService
{
    public function __construct(
        private Database $db,
        private UserService $userService,
        private CompanyService $companyService,
        private ContactService $contactService,
    ) {
    }

    // ==================== СТАДИИ ====================

    /**
     * Загрузить стадии лидов с облака и создать недостающие в коробке
     * 
     * @return array{cloud: int, box: int, created: int}
     */
    public function syncStages(): array
    {

    // 👇 ТЕСТОВАЯ ЗАПИСЬ
    file_put_contents(dirname(__DIR__) . '/logs/debug.log', date('Y-m-d H:i:s') . " - syncStages() вызван\n", FILE_APPEND);

        $cloudClient = new BitrixClient($this->userService->webhook('cloud'));
        $boxClient = new BitrixClient($this->userService->webhook('box'));

        // 1. Загружаем стадии с обоих порталов
        $cloudStages = $cloudClient->getLeadStages();
        $boxStages = $boxClient->getLeadStages();

        // 2. Сохраняем загруженные стадии в БД
        $this->replacePortalStages('cloud', $cloudStages);
        $this->replacePortalStages('box', $boxStages);

        // 3. Создаём недостающие стадии в коробке
        $created = $this->createMissingStages($cloudStages, $boxStages);

        // 4. Автоматическое сопоставление стадий по названию
        $this->autoMatchStages();

        return [
            'cloud' => count($cloudStages),
            'box' => count($boxStages),
            'created' => $created,
        ];
    }

    /**
     * Создать в коробке стадии, которых там нет
     * 
     * @param list<array<string, mixed>> $cloudStages
     * @param list<array<string, mixed>> $boxStages
     * @return int Количество созданных стадий
     */
    private function createMissingStages(array $cloudStages, array $boxStages): int
{
    $logFile = dirname(__DIR__) . '/logs/debug.log';
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " === createMissingStages START ===\n", FILE_APPEND);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " cloudStages count: " . count($cloudStages) . "\n", FILE_APPEND);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " boxStages count: " . count($boxStages) . "\n", FILE_APPEND);

    // 1. Индексируем стадии коробки по названию и STATUS_ID
    $boxStageNames = [];
    $existingStatusIds = [];
    foreach ($boxStages as $box) {
        $name = trim((string) ($box['NAME'] ?? ''));           // ← БОЛЬШАЯ БУКВА
        $statusId = (string) ($box['STATUS_ID'] ?? '');        // ← БОЛЬШАЯ БУКВА
        if ($name !== '') {
            $boxStageNames[$name] = true;
        }
        if ($statusId !== '') {
            $existingStatusIds[] = $statusId;
        }
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . " boxStageNames: " . json_encode(array_keys($boxStageNames)) . "\n", FILE_APPEND);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " existingStatusIds: " . json_encode($existingStatusIds) . "\n", FILE_APPEND);

    $boxClient = new BitrixClient($this->userService->webhook('box'));
    $created = 0;

    // 2. Проходим по стадиям облака
    foreach ($cloudStages as $cloud) {
        $name = trim((string) ($cloud['NAME'] ?? ''));              // ← БОЛЬШАЯ БУКВА
        $originalStatusId = (string) ($cloud['STATUS_ID'] ?? '');   // ← БОЛЬШАЯ БУКВА
        $categoryId = (int) ($cloud['CATEGORY_ID'] ?? 0);           // ← БОЛЬШАЯ БУКВА

        file_put_contents($logFile, date('Y-m-d H:i:s') . " Обработка стадии: {$name} ({$originalStatusId})\n", FILE_APPEND);

        if ($name === '' || $originalStatusId === '') {
            file_put_contents($logFile, date('Y-m-d H:i:s') . "  ⏭️ Пропускаем: пустое имя или статус ID\n", FILE_APPEND);
            continue;
        }

        // Пропускаем системные стадии
        if (in_array($originalStatusId, ['NEW', 'CONVERTED', 'JUNK'], true)) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . "  ⏭️ Пропускаем системную стадию: {$originalStatusId}\n", FILE_APPEND);
            continue;
        }

        // Проверяем, есть ли такая стадия в коробке по названию
        if (isset($boxStageNames[$name])) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . "  ⏭️ Стадия уже есть в коробке по названию: {$name}\n", FILE_APPEND);
            continue;
        }

        // Генерируем уникальный STATUS_ID
        $statusId = $originalStatusId;
        $counter = 1;
        while (in_array($statusId, $existingStatusIds, true)) {
            $statusId = $originalStatusId . '_' . $counter;
            $counter++;
        }
        $existingStatusIds[] = $statusId;

        file_put_contents($logFile, date('Y-m-d H:i:s') . "  🆕 Генерируем новый STATUS_ID: {$statusId} (было: {$originalStatusId})\n", FILE_APPEND);

        try {
            $fields = [
                'ENTITY_ID' => 'STATUS',
                'STATUS_ID' => $statusId,
                'NAME' => $name,
                'CATEGORY_ID' => $categoryId,
                'SYSTEM' => 'N',
            ];

            file_put_contents($logFile, date('Y-m-d H:i:s') . "  📤 Отправляем в Bitrix: " . json_encode($fields, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

            $result = $boxClient->addLeadStage($fields);
            
            file_put_contents($logFile, date('Y-m-d H:i:s') . "  ✅ СТАДИЯ СОЗДАНА! ID: {$result}\n", FILE_APPEND);
            $created++;
            $boxStageNames[$name] = true;

        } catch (Throwable $e) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . "  ❌ ОШИБКА: " . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents($logFile, date('Y-m-d H:i:s') . "  ❌ Данные: " . json_encode($fields, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        }
    }

    if ($created > 0) {
        $updatedBoxStages = $boxClient->getLeadStages();
        $this->replacePortalStages('box', $updatedBoxStages);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " 📊 Обновлён список стадий коробки в БД (создано: {$created})\n", FILE_APPEND);
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . " === createMissingStages END, created: {$created} ===\n", FILE_APPEND);

    return $created;
}

    /**
     * Сопоставить стадии автоматически по названию
     * 
     * @return int Количество новых сопоставлений
     */
    public function autoMatchStages(): int
    {
        $pdo = $this->db->pdo();
        $cloudStages = $this->listStages('cloud');
        $boxStages = $this->listStages('box');

        // Индексируем стадии коробки по названию
        $boxByName = [];
        foreach ($boxStages as $box) {
            $name = trim((string) ($box['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $boxByName[$name] = $box;
        }

        // Получаем уже сопоставленные стадии
        $existing = $this->getStageMappings();
        $created = 0;

        $insert = $pdo->prepare(
            'INSERT INTO stage_mappings 
            (cloud_category_id, cloud_status_id, box_category_id, box_status_id, name, match_type, created_at, updated_at)
            VALUES (:cloud_category, :cloud_status, :box_category, :box_status, :name, :match_type, :created_at, :updated_at)'
        );

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        foreach ($cloudStages as $cloud) {
            $cloudCategory = (int) ($cloud['category_id'] ?? 0);
            $cloudStatus = (string) ($cloud['status_id'] ?? '');
            $key = $cloudCategory . '|' . $cloudStatus;

            // Пропускаем уже сопоставленные
            if (isset($existing[$key])) {
                continue;
            }

            $name = trim((string) ($cloud['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            // Ищем совпадение по названию в коробке
            if (!isset($boxByName[$name])) {
                continue;
            }

            $box = $boxByName[$name];
            $boxCategory = (int) ($box['category_id'] ?? 0);
            $boxStatus = (string) ($box['status_id'] ?? '');

            $insert->execute([
                'cloud_category' => $cloudCategory,
                'cloud_status' => $cloudStatus,
                'box_category' => $boxCategory,
                'box_status' => $boxStatus,
                'name' => $name,
                'match_type' => 'auto',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $existing[$key] = true;
            $created++;
        }

        return $created;
    }

    /**
     * Получить стадии для портала
     * 
     * @return list<array<string, mixed>>
     */
    public function listStages(string $portal): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM stage_import WHERE portal = :portal ORDER BY category_id, sort, name'
        );
        $stmt->execute(['portal' => $portal]);

        return $stmt->fetchAll();
    }

    /**
     * Получить все сопоставления стадий
     * 
     * @return array<string, array<string, mixed>>
     */
    public function getStageMappings(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT * FROM stage_mappings ORDER BY cloud_category_id, cloud_status_id'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $key = (int) $row['cloud_category_id'] . '|' . $row['cloud_status_id'];
            $map[$key] = $row;
        }

        return $map;
    }

    /**
     * Получить сопоставление стадии по облачным данным
     * 
     * @return array<string, mixed>|null
     */
    public function getStageMappingByCloud(int $categoryId, string $statusId): ?array
    {
        $key = $categoryId . '|' . $statusId;
        $mappings = $this->getStageMappings();
        
        return $mappings[$key] ?? null;
    }

    /**
     * Заменить стадии в БД
     * 
     * @param list<array<string, mixed>> $stages
     */
    private function replacePortalStages(string $portal, array $stages): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM stage_import WHERE portal = :portal');
            $delete->execute(['portal' => $portal]);

            $insert = $pdo->prepare(
                'INSERT INTO stage_import 
                (portal, category_id, status_id, name, sort, is_final, raw_json, synced_at)
                VALUES (:portal, :category_id, :status_id, :name, :sort, :is_final, :raw_json, :synced_at)'
            );

            $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            foreach ($stages as $stage) {
                $insert->execute([
                    'portal' => $portal,
                    'category_id' => (int) ($stage['CATEGORY_ID'] ?? 0),
                    'status_id' => (string) ($stage['STATUS_ID'] ?? ''),
                    'name' => (string) ($stage['NAME'] ?? 'Без названия'),
                    'sort' => (int) ($stage['SORT'] ?? 0),
                    'is_final' => (int) ($stage['IS_FINAL'] ?? 0),
                    'raw_json' => json_encode($stage, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'synced_at' => $now,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ==================== ЛИДЫ ====================

    /**
     * Загрузить лиды с обоих порталов
     * 
     * @return array{cloud: int, box: int}
     */
    public function syncLeads(): array
    {
        $cloud = (new BitrixClient($this->userService->webhook('cloud')))->getLeads();
        $box = (new BitrixClient($this->userService->webhook('box')))->getLeads();

        $this->replacePortalLeads('cloud', $cloud);
        $this->replacePortalLeads('box', $box);

        return [
            'cloud' => count($cloud),
            'box' => count($box),
        ];
    }

    /**
     * Перенести лиды из облака в коробку
     * 
     * @return array{created: int, skipped: int, errors: int, details: array<int, string>}
     */
    public function migrateLeads(): array
    {
        $cloudLeads = $this->listLeads('cloud');
        $result = [
            'created' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        $userMappings = $this->userService->mappingsByCloudId();
        $companyMappings = $this->companyService->getAllMappings();
        $contactMappings = $this->contactService->getAllMappings();
        $stageMappings = $this->getStageMappings();

        foreach ($cloudLeads as $cloud) {
            $cloudId = (int) $cloud['bitrix_id'];

            // Проверяем, не перенесён ли уже лид
            $existing = $this->getMappingByCloudId($cloudId);
            if ($existing && $existing['box_lead_id']) {
                $result['skipped']++;
                $result['details'][] = "Лид #{$cloudId} уже перенесён";
                continue;
            }

            // Определяем компанию в коробке
            $cloudCompanyId = (int) ($cloud['company_id'] ?? 0);
            $boxCompanyId = $companyMappings[$cloudCompanyId]['box_company_id'] ?? null;

            // Определяем контакт в коробке
            $cloudContactId = (int) ($cloud['contact_id'] ?? 0);
            $boxContactId = $contactMappings[$cloudContactId]['box_contact_id'] ?? null;

            // Определяем ответственного в коробке
            $assignedCloudId = (int) ($cloud['assigned_by_id'] ?? 0);
            $assignedBoxId = $userMappings[$assignedCloudId]['box_user_id'] ?? null;

            // Определяем стадию в коробке
            $cloudCategoryId = (int) ($cloud['category_id'] ?? 0);
            $cloudStatusId = (string) ($cloud['status_id'] ?? '');
            $stageKey = $cloudCategoryId . '|' . $cloudStatusId;
            $stageMapping = $stageMappings[$stageKey] ?? null;
            $boxCategoryId = $stageMapping['box_category_id'] ?? 0;
            $boxStatusId = $stageMapping['box_status_id'] ?? '';

            try {
                $boxId = $this->createLeadInBox(
                    $cloud,
                    $boxCompanyId,
                    $boxContactId,
                    $assignedBoxId,
                    $boxCategoryId,
                    $boxStatusId
                );

                $this->saveMapping(
                    $cloudId,
                    $boxId,
                    (string) ($cloud['title'] ?? 'Без названия'),
                    $cloudCategoryId,
                    $cloudStatusId,
                    $boxCategoryId,
                    $boxStatusId,
                    $cloudCompanyId,
                    $boxCompanyId,
                    $cloudContactId,
                    $boxContactId,
                    $assignedCloudId,
                    $assignedBoxId
                );
                $result['created']++;
                $result['details'][] = "Лид #{$cloudId} → #{$boxId}";
            } catch (Throwable $e) {
                $result['errors']++;
                $result['details'][] = "Ошибка при переносе лида #{$cloudId}: " . $e->getMessage();
                error_log("Ошибка переноса лида #{$cloudId}: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Создать лид в коробке
     */
    private function createLeadInBox(
        array $cloudLead,
        ?int $boxCompanyId,
        ?int $boxContactId,
        ?int $assignedBoxId,
        int $boxCategoryId,
        string $boxStatusId
    ): int {
        $fields = [
            'TITLE' => (string) ($cloudLead['title'] ?? 'Без названия'),
        ];

        if ($boxCategoryId > 0) {
            $fields['CATEGORY_ID'] = $boxCategoryId;
        }

        if ($boxStatusId !== '') {
            $fields['STATUS_ID'] = $boxStatusId;
        }

        if ($boxCompanyId > 0) {
            $fields['COMPANY_ID'] = $boxCompanyId;
        }

        if ($boxContactId > 0) {
            $fields['CONTACT_ID'] = $boxContactId;
        }

        if ($assignedBoxId > 0) {
            $fields['ASSIGNED_BY_ID'] = $assignedBoxId;
        }

        if (!empty($cloudLead['opportunity'])) {
            $fields['OPPORTUNITY'] = (float) $cloudLead['opportunity'];
        }

        if (!empty($cloudLead['currency'])) {
            $fields['CURRENCY_ID'] = (string) $cloudLead['currency'];
        }

        if (!empty($cloudLead['source_id'])) {
            $fields['SOURCE_ID'] = (string) $cloudLead['source_id'];
        }

        $client = new BitrixClient($this->userService->webhook('box'));
        return $client->addLead($fields);
    }

    /**
     * Сохранить сопоставление лида
     */
    private function saveMapping(
        int $cloudId,
        int $boxId,
        string $title,
        int $cloudCategoryId,
        string $cloudStatusId,
        int $boxCategoryId,
        string $boxStatusId,
        int $cloudCompanyId,
        ?int $boxCompanyId,
        int $cloudContactId,
        ?int $boxContactId,
        int $assignedCloudId,
        ?int $assignedBoxId
    ): void {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO lead_mappings 
            (cloud_lead_id, box_lead_id, title, 
             cloud_category_id, cloud_status_id, box_category_id, box_status_id,
             cloud_company_id, box_company_id, 
             cloud_contact_id, box_contact_id,
             assigned_by_cloud_id, assigned_by_box_id, 
             match_type, created_at, updated_at)
            VALUES (
             :cloud_id, :box_id, :title,
             :cloud_category, :cloud_status, :box_category, :box_status,
             :cloud_company, :box_company,
             :cloud_contact, :box_contact,
             :assigned_cloud, :assigned_box,
             :match_type, :created_at, :updated_at)'
        );

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $stmt->execute([
            'cloud_id' => $cloudId,
            'box_id' => $boxId,
            'title' => $title,
            'cloud_category' => $cloudCategoryId,
            'cloud_status' => $cloudStatusId,
            'box_category' => $boxCategoryId,
            'box_status' => $boxStatusId,
            'cloud_company' => $cloudCompanyId ?: null,
            'box_company' => $boxCompanyId,
            'cloud_contact' => $cloudContactId ?: null,
            'box_contact' => $boxContactId,
            'assigned_cloud' => $assignedCloudId ?: null,
            'assigned_box' => $assignedBoxId,
            'match_type' => 'auto',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Получить сопоставление по ID лида в облаке
     * 
     * @return array<string, mixed>|null
     */
    public function getMappingByCloudId(int $cloudId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM lead_mappings WHERE cloud_lead_id = :cloud_id'
        );
        $stmt->execute(['cloud_id' => $cloudId]);
        $row = $stmt->fetch();
        
        return $row === false ? null : $row;
    }

    /**
     * Получить все сопоставления лидов
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllMappings(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT * FROM lead_mappings ORDER BY cloud_lead_id'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['cloud_lead_id']] = $row;
        }

        return $map;
    }

    /**
     * Получить список лидов для портала
     * 
     * @return list<array<string, mixed>>
     */
    public function listLeads(string $portal): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM lead_import WHERE portal = :portal
             ORDER BY bitrix_id DESC'
        );
        $stmt->execute(['portal' => $portal]);

        return $stmt->fetchAll();
    }

    /**
     * Заменить лиды в БД (полная перезапись)
     * 
     * @param list<array<string, mixed>> $leads
     */
    private function replacePortalLeads(string $portal, array $leads): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM lead_import WHERE portal = :portal');
            $delete->execute(['portal' => $portal]);

            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO lead_import (
                    portal, bitrix_id, title, category_id, status_id, assigned_by_id,
                    company_id, contact_id, opportunity, currency, source_id, stage_id,
                    raw_json, synced_at
                ) VALUES (
                    :portal, :bitrix_id, :title, :category_id, :status_id, :assigned_by_id,
                    :company_id, :contact_id, :opportunity, :currency, :source_id, :stage_id,
                    :raw_json, :synced_at
                )
                SQL
            );

            $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            foreach ($leads as $lead) {
                $insert->execute([
                    'portal' => $portal,
                    'bitrix_id' => (int) $lead['ID'],
                    'title' => (string) ($lead['TITLE'] ?? 'Без названия'),
                    'category_id' => (int) ($lead['CATEGORY_ID'] ?? 0),
                    'status_id' => (string) ($lead['STATUS_ID'] ?? ''),
                    'assigned_by_id' => isset($lead['ASSIGNED_BY_ID']) ? (int) $lead['ASSIGNED_BY_ID'] : null,
                    'company_id' => isset($lead['COMPANY_ID']) ? (int) $lead['COMPANY_ID'] : null,
                    'contact_id' => isset($lead['CONTACT_ID']) ? (int) $lead['CONTACT_ID'] : null,
                    'opportunity' => isset($lead['OPPORTUNITY']) ? (float) $lead['OPPORTUNITY'] : null,
                    'currency' => (string) ($lead['CURRENCY_ID'] ?? ''),
                    'source_id' => (string) ($lead['SOURCE_ID'] ?? ''),
                    'stage_id' => isset($lead['STAGE_ID']) ? (int) $lead['STAGE_ID'] : null,
                    'raw_json' => json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
     * Удалить все сопоставления лидов
     */
    public function clearMappings(): void
    {
        $this->db->pdo()->exec('DELETE FROM lead_mappings');
    }

    /**
     * Получить лид по ID из кеша
     * 
     * @return array<string, mixed>|null
     */
    public function getLeadById(string $portal, int $bitrixId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM lead_import WHERE portal = :portal AND bitrix_id = :bitrix_id'
        );
        $stmt->execute(['portal' => $portal, 'bitrix_id' => $bitrixId]);
        $row = $stmt->fetch();
        
        return $row === false ? null : $row;
    }
}