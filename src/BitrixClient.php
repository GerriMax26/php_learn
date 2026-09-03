<?php

declare(strict_types=1);

final class BitrixClient
{
    public function __construct(private string $webhookUrl)
    {
        $this->webhookUrl = rtrim($webhookUrl, '/') . '/';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $url = $this->webhookUrl . ltrim($method, '/') . '.json';
        $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Не удалось инициализировать cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('Ошибка запроса к Bitrix: ' . $error);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Bitrix вернул не-JSON (HTTP ' . $status . ')');
        }

        if (isset($decoded['error'])) {
            $description = (string) ($decoded['error_description'] ?? $decoded['error']);
            throw new RuntimeException('Bitrix REST: ' . $description);
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUsers(): array
    {
        $users = [];
        foreach (['Y', 'N'] as $active) {
            $start = 0;
            do {
                $response = $this->call('user.get', [
                    'start' => $start,
                    'ADMIN_MODE' => 'Y',
                    'SORT' => 'LAST_NAME',
                    'ORDER' => 'ASC',
                    'FILTER' => [
                        'ACTIVE' => $active,
                    ],
                ]);

                $batch = $response['result'] ?? [];
                if (!is_array($batch)) {
                    break;
                }

                foreach ($batch as $user) {
                    if (is_array($user) && isset($user['ID'])) {
                        $users[(string) $user['ID']] = $user;
                    }
                }

                $start = isset($response['next']) ? (int) $response['next'] : null;
            } while ($start !== null);
        }

        return array_values($users);
    }

    /**
     * Получить список компаний из портала
     * 
     * @return list<array<string, mixed>>
     */
    public function getCompanies(): array
    {
        $companies = [];
        $start = 0;
        do {
            $response = $this->call('crm.company.list', [
                'start' => $start,
                'select' => [
                    'ID', 'TITLE', 'ASSIGNED_BY_ID', 'COMPANY_TYPE', 
                    'INDUSTRY', 'PHONE', 'EMAIL', 'WEB', 'ADDRESS'
                ],
                'order' => ['TITLE' => 'ASC'],
            ]);

            $batch = $response['result'] ?? [];
            if (!is_array($batch)) {
                break;
            }

            foreach ($batch as $company) {
                if (is_array($company) && isset($company['ID'])) {
                    $companies[(string) $company['ID']] = $company;
                }
            }

            $start = isset($response['next']) ? (int) $response['next'] : null;
        } while ($start !== null);

        return array_values($companies);
    }

    /**
     * Создать компанию в портале
     * 
     * @param array<string, mixed> $fields
     * @return int ID созданной компании
     */
    public function addCompany(array $fields): int
    {
        $response = $this->call('crm.company.add', ['fields' => $fields]);
        
        if (!isset($response['result'])) {
            throw new RuntimeException('Не удалось создать компанию: ' . json_encode($response));
        }
        
        return (int) $response['result'];
    }

    /**
     * Получить список контактов из портала
     * 
     * @return list<array<string, mixed>>
     */
    public function getContacts(): array
    {
        $contacts = [];
        $start = 0;
        do {
            $response = $this->call('crm.contact.list', [
                'start' => $start,
                'select' => [
                    'ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 
                    'EMAIL', 'PHONE', 'COMPANY_ID', 'ASSIGNED_BY_ID'
                ],
                'order' => ['LAST_NAME' => 'ASC'],
            ]);

            $batch = $response['result'] ?? [];
            if (!is_array($batch)) {
                break;
            }

            foreach ($batch as $contact) {
                if (is_array($contact) && isset($contact['ID'])) {
                    $contacts[(string) $contact['ID']] = $contact;
                }
            }

            $start = isset($response['next']) ? (int) $response['next'] : null;
        } while ($start !== null);

        return array_values($contacts);
    }

    /**
     * Создать контакт в портале
     * 
     * @param array<string, mixed> $fields
     * @return int ID созданного контакта
     */
    public function addContact(array $fields): int
    {
        $response = $this->call('crm.contact.add', ['fields' => $fields]);
        
        if (!isset($response['result'])) {
            throw new RuntimeException('Не удалось создать контакт: ' . json_encode($response));
        }
        
        return (int) $response['result'];
    }

    /**
     * Получить стадии лидов из портала
     * 
     * @param int $categoryId ID категории (по умолчанию 0 - основная воронка)
     * @return list<array<string, mixed>>
     */
    public function getLeadStages(int $categoryId = 0): array
    {
        $response = $this->call('crm.status.list', [
            'filter' => [
                'ENTITY_ID' => 'STATUS',
            ],
            'select' => ['ID', 'STATUS_ID', 'NAME', 'SORT', 'CATEGORY_ID'],
            'order' => ['SORT' => 'ASC'],
        ]);

        $stages = [];
        $batch = $response['result'] ?? [];
        
        if (!is_array($batch)) {
            return [];
        }

        foreach ($batch as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $entityId = $stage['ENTITY_ID'] ?? '';
            if ($entityId !== 'STATUS') {
                continue;
            }

            $stageCategoryId = (int) ($stage['CATEGORY_ID'] ?? 0);
            if ($stageCategoryId !== $categoryId) {
                continue;
            }

            $stages[] = [
                'ID' => $stage['ID'] ?? null,
                'STATUS_ID' => $stage['STATUS_ID'] ?? '',
                'NAME' => $stage['NAME'] ?? 'Без названия',
                'SORT' => (int) ($stage['SORT'] ?? 0),
                'CATEGORY_ID' => $stageCategoryId,
                'ENTITY_ID' => $entityId,
            ];
        }

        return $stages;
    }

    /**
     * Создать стадию для лидов
     * 
     * @param array<string, mixed> $fields
     * @return string ID созданной стадии
     */
    public function addLeadStage(array $fields): string
    {
        $response = $this->call('crm.status.add', ['fields' => $fields]);
        
        if (!isset($response['result'])) {
            throw new RuntimeException('Не удалось создать стадию: ' . json_encode($response));
        }
        
        return (string) $response['result'];
    }

    /**
     * Создать лид в портале
     * 
     * @param array<string, mixed> $fields
     * @return int ID созданного лида
     */
    public function addLead(array $fields): int
    {
        $response = $this->call('crm.lead.add', ['fields' => $fields]);
        
        if (!isset($response['result'])) {
            throw new RuntimeException('Не удалось создать лид: ' . json_encode($response));
        }
        
        return (int) $response['result'];
    }

    /**
     * Получить список лидов из портала
     * 
     * @return list<array<string, mixed>>
     */
    public function getLeads(): array
    {
        $leads = [];
        $start = 0;
        do {
            $response = $this->call('crm.lead.list', [
                'start' => $start,
                'select' => [
                    'ID', 'TITLE', 'CATEGORY_ID', 'STATUS_ID', 'ASSIGNED_BY_ID',
                    'COMPANY_ID', 'CONTACT_ID', 'OPPORTUNITY', 'CURRENCY_ID',
                    'SOURCE_ID', 'STAGE_ID'
                ],
                'order' => ['ID' => 'ASC'],
            ]);

            $batch = $response['result'] ?? [];
            if (!is_array($batch)) {
                break;
            }

            foreach ($batch as $lead) {
                if (is_array($lead) && isset($lead['ID'])) {
                    $leads[(string) $lead['ID']] = $lead;
                }
            }

            $start = isset($response['next']) ? (int) $response['next'] : null;
        } while ($start !== null);

        return array_values($leads);
    }
}