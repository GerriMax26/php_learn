<?php

declare(strict_types=1);

final class DemoUsers
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function cloud(): array
    {
        return [
            self::user(1, 'ivanov', 'Иван', 'Иванов', 'ivanov@company.ru', 'Директор', true),
            self::user(2, 'petrova', 'Анна', 'Петрова', 'petrova@company.ru', 'Бухгалтер', true),
            self::user(3, 'sidorov', 'Павел', 'Сидоров', 'sidorov@company.ru', 'Менеджер', true),
            self::user(4, 'oldmail', 'Елена', 'Козлова', 'kozlova.old@company.ru', 'Маркетолог', true),
            self::user(5, 'exuser', 'Олег', 'Смирнов', 'smirnov@company.ru', 'Разработчик', false),
            self::user(6, 'orphan', 'Мария', 'Новикова', 'novikova@company.ru', 'HR', true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function box(): array
    {
        return [
            self::user(101, 'ivanov', 'Иван', 'Иванов', 'ivanov@company.ru', 'Генеральный директор', true),
            self::user(102, 'petrova', 'Анна', 'Петрова', 'petrova@company.ru', 'Главный бухгалтер', true),
            self::user(103, 'sidorov.p', 'Павел', 'Сидоров', 'p.sidorov@company.ru', 'Менеджер по продажам', true),
            self::user(104, 'kozlova', 'Елена', 'Козлова', 'kozlova@company.ru', 'Маркетолог', true),
            self::user(105, 'smirnov', 'Олег', 'Смирнов', 'smirnov@company.ru', 'Программист', false),
            self::user(106, 'admin', 'Администратор', 'Системный', 'admin@box.local', 'Админ портала', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function user(
        int $id,
        string $login,
        string $name,
        string $lastName,
        string $email,
        string $position,
        bool $active
    ): array {
        return [
            'ID' => (string) $id,
            'XML_ID' => $login,
            'ACTIVE' => $active ? 'Y' : 'N',
            'LOGIN' => $login,
            'NAME' => $name,
            'LAST_NAME' => $lastName,
            'SECOND_NAME' => '',
            'EMAIL' => $email,
            'WORK_POSITION' => $position,
        ];
    }
}
