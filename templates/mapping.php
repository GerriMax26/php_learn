<?php
/** @var string $csrf */
/** @var bool $demoMode */
/** @var list<array<string, mixed>> $cloudUsers */
/** @var list<array<string, mixed>> $boxUsers */
/** @var array<int, array<string, mixed>> $mappings */
$view = 'mapping';

$boxOptions = [];
foreach ($boxUsers as $box) {
    $boxOptions[] = [
        'id' => (int) $box['bitrix_id'],
        'name' => UserService::displayName($box),
        'email' => (string) ($box['email'] ?? ''),
        'active' => (int) $box['active'],
        'position' => (string) ($box['work_position'] ?? ''),
    ];
}
?>
<section class="page" data-page="mapping" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <header class="page-head row">
        <div>
            <h1>Сопоставление пользователей</h1>
            <p>
                Облако: <strong><?= count($cloudUsers) ?></strong>
                · Коробка: <strong><?= count($boxUsers) ?></strong>
                <?php if ($demoMode): ?><span class="badge">демо</span><?php endif; ?>
            </p>
        </div>
        <div class="actions">
            <button type="button" class="btn" id="sync-btn">Загрузить user.get</button>
            <button type="button" class="btn" id="auto-hint">Подсветить совпадения email</button>
            <button type="button" class="btn primary" id="save-btn">Сохранить соответствия</button>
        </div>
    </header>

    <div id="flash" class="flash" hidden></div>

    <div class="toolbar">
        <input type="search" id="search" placeholder="Поиск по ФИО, email, ID…">
        <label class="check compact">
            <input type="checkbox" id="unmapped-only"> Только без пары
        </label>
        <span class="stat" id="stat"></span>
    </div>

    <?php if ($cloudUsers === [] && $boxUsers === []): ?>
        <div class="card empty">
            <p>Списки ещё не загружены. Нажмите «Загрузить user.get» — данные придут с обоих порталов и попадут в SQLite.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap card">
            <table class="map-table" id="map-table">
                <thead>
                    <tr>
                        <th>Облако</th>
                        <th></th>
                        <th>Коробка</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cloudUsers as $cloud):
                    $cloudId = (int) $cloud['bitrix_id'];
                    $mapped = $mappings[$cloudId] ?? null;
                    $selected = $mapped ? (int) $mapped['box_user_id'] : 0;
                    $matchType = $mapped['match_type'] ?? '';
                    $cloudName = UserService::displayName($cloud);
                    $cloudEmail = (string) ($cloud['email'] ?? '');
                    ?>
                    <tr data-cloud-id="<?= $cloudId ?>"
                        data-search="<?= htmlspecialchars(mb_strtolower($cloudName . ' ' . $cloudEmail . ' ' . $cloudId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-email="<?= htmlspecialchars($cloudEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <td>
                            <div class="person">
                                <strong><?= htmlspecialchars($cloudName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($cloudEmail !== '' ? $cloudEmail : 'нет email', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                <small>
                                    ID <?= $cloudId ?>
                                    <?php if (!(int) $cloud['active']): ?><em>неактивен</em><?php endif; ?>
                                    <?php if ($cloud['work_position']): ?>
                                        · <?= htmlspecialchars((string) $cloud['work_position'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </td>
                        <td class="arrow">→</td>
                        <td>
                            <select class="box-select" data-match-type="<?= htmlspecialchars((string) $matchType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <option value="">— не сопоставлен —</option>
                                <?php foreach ($boxOptions as $opt): ?>
                                    <option value="<?= $opt['id'] ?>"
                                            data-email="<?= htmlspecialchars($opt['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        <?= $selected === $opt['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt['name'] . ($opt['email'] !== '' ? ' · ' . $opt['email'] : '') . ' · #' . $opt['id'] . ($opt['active'] ? '' : ' (неактивен)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="row-hint"></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
