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
}
