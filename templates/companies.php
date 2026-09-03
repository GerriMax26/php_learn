<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $cloudCompanies */
/** @var list<array<string, mixed>> $boxCompanies */
/** @var array<int, array<string, mixed>> $mappings */
/** @var array<int, array<string, mixed>> $userMappings */

$view = 'companies';
?>
<section class="page" data-page="companies" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <header class="page-head row">
        <div>
            <h1>Перенос компаний</h1>
            <p>
                Облако: <strong><?= count($cloudCompanies) ?></strong>
                · Коробка: <strong><?= count($boxCompanies) ?></strong>
                <?php if (!empty($boxCompanies)): ?>
                    <span class="badge">для сопоставления</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="actions">
            <button type="button" class="btn" id="sync-companies-btn">Загрузить компании</button>
            <button type="button" class="btn primary" id="migrate-btn">Перенести компании</button>
        </div>
    </header>

    <div id="flash" class="flash" hidden></div>

    <?php if ($cloudCompanies === [] && $boxCompanies === []): ?>
        <div class="card empty">
            <p>Компании ещё не загружены. Нажмите <strong>«Загрузить компании»</strong> — данные придут с обоих порталов и сохранятся в SQLite.</p>
            <p style="margin-top: 0.5rem; color: var(--muted);">
                💡 После загрузки вы увидите список компаний из облака и сможете перенести их в коробку.
                Ответственные назначаются автоматически на основе сопоставлений пользователей.
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap card">
            <table class="map-table" id="company-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Компания в облаке</th>
                        <th style="width: 15%;">Ответственный</th>
                        <th style="width: 5%;"></th>
                        <th style="width: 30%;">Компания в коробке</th>
                        <th style="width: 15%;">Ответственный в коробке</th>
                        <th style="width: 5%;">Статус</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                // Собираем ID пользователей для быстрого доступа
                $cloudUserNames = [];
                foreach ($cloudCompanies as $cloud) {
                    $assignedId = (int) ($cloud['assigned_by_id'] ?? 0);
                    if ($assignedId > 0) {
                        // Ищем пользователя в кеше (нужно будет добавить метод в UserService)
                        $cloudUserNames[$assignedId] = 'ID ' . $assignedId;
                    }
                }
                ?>
                <?php foreach ($cloudCompanies as $cloud):
                    $cloudId = (int) $cloud['bitrix_id'];
                    $mapped = $mappings[$cloudId] ?? null;
                    $assignedCloudId = (int) ($cloud['assigned_by_id'] ?? 0);
                    
                    // Ищем ответственного в коробке
                    $assignedBoxId = null;
                    $assignedBoxName = '';
                    if ($assignedCloudId > 0 && isset($userMappings[$assignedCloudId])) {
                        $assignedBoxId = (int) $userMappings[$assignedCloudId]['box_user_id'];
                        $assignedBoxName = 'Пользователь #' . $assignedBoxId;
                    }
                    
                    $isMigrated = $mapped && $mapped['box_company_id'] > 0;
                ?>
                    <tr data-cloud-id="<?= $cloudId ?>">
                        <td>
                            <div class="person">
                                <strong><?= htmlspecialchars($cloud['title'] ?? 'Без названия', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                <small>ID <?= $cloudId ?></small>
                                <?php if (!empty($cloud['company_type'])): ?>
                                    <small>· <?= htmlspecialchars($cloud['company_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                                <?php endif; ?>
                                <?php if (!empty($cloud['industry'])): ?>
                                    <small>· <?= htmlspecialchars($cloud['industry'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($assignedCloudId > 0): ?>
                                <?php 
                                    // Пытаемся найти пользователя в загруженных данных
                                    $userName = 'ID ' . $assignedCloudId;
                                ?>
                                <span><?= htmlspecialchars($userName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="empty">Не назначен</span>
                            <?php endif; ?>
                        </td>
                        <td class="arrow">→</td>
                        <td>
                            <?php if ($isMigrated): ?>
                                <div class="person" style="color: var(--ok);">
                                    <strong><?= htmlspecialchars($mapped['title'] ?? $cloud['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                    <small>ID <?= $mapped['box_company_id'] ?></small>
                                </div>
                            <?php else: ?>
                                <span class="empty">Будет создана</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($assignedBoxId > 0): ?>
                                <span style="color: var(--ok);"><?= htmlspecialchars($assignedBoxName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php elseif ($assignedCloudId > 0): ?>
                                <span style="color: var(--warn);">⚠️ Не сопоставлен</span>
                            <?php else: ?>
                                <span class="empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isMigrated): ?>
                                <span class="badge" style="background: var(--ok); color: white;">✅ Перенесена</span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--muted); color: white;">Ожидает</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <?php if (empty($cloudCompanies)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: var(--muted);">
                            Нет компаний в облаке. Нажмите «Загрузить компании».
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($cloudCompanies)): ?>
        <div class="card" style="margin-top: 1rem; background: var(--bg);">
            <p style="margin: 0; color: var(--muted);">
                💡 <strong>Как это работает:</strong>
                <br>
                1. Нажмите <strong>«Загрузить компании»</strong> — данные загрузятся с обоих порталов.
                <br>
                2. Нажмите <strong>«Перенести компании»</strong> — каждая компания из облака будет создана в коробке.
                <br>
                3. Ответственный назначается автоматически, если пользователь сопоставлен в разделе «Пользователи».
                <br>
                4. Компании, которые уже есть в коробке, будут пропущены (проверка по названию).
            </p>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</section>