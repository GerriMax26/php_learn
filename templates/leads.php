<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $cloudLeads */
/** @var list<array<string, mixed>> $boxLeads */
/** @var array<int, array<string, mixed>> $mappings */
/** @var array<int, array<string, mixed>> $userMappings */
/** @var array<int, array<string, mixed>> $companyMappings */
/** @var array<int, array<string, mixed>> $contactMappings */
/** @var array<string, array<string, mixed>> $stageMappings */
/** @var list<array<string, mixed>> $cloudStages */
/** @var list<array<string, mixed>> $boxStages */

$view = 'leads';
?>
<section class="page" data-page="leads" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <header class="page-head row">
        <div>
            <h1>💼 Миграция лидов</h1>
            <p>
                Облако: <strong><?= count($cloudLeads) ?></strong>
                · Коробка: <strong><?= count($boxLeads) ?></strong>
                <?php if (!empty($cloudStages)): ?>
                    · Стадий в облаке: <strong><?= count($cloudStages) ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <div class="actions">
            <button type="button" class="btn" id="sync-stages-btn">Загрузить стадии</button>
            <button type="button" class="btn" id="sync-leads-btn">Загрузить лиды</button>
            <button type="button" class="btn primary" id="migrate-btn">Перенести лиды</button>
        </div>
    </header>

    <div id="flash" class="flash" hidden></div>

    <?php if ($cloudLeads === [] && $boxLeads === []): ?>
        <div class="card empty">
            <p>Лиды ещё не загружены.</p>
            <p style="margin-top: 0.5rem; color: var(--muted);">
                1. Нажмите <strong>«Загрузить стадии»</strong> — загрузятся стадии лидов с обоих порталов.
                <br>
                2. Нажмите <strong>«Загрузить лиды»</strong> — загрузятся сами лиды.
                <br>
                3. Нажмите <strong>«Перенести лиды»</strong> — лиды будут созданы в коробке с привязкой к стадиям, компаниям, контактам и ответственным.
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap card">
            <table class="map-table" id="lead-table">
                <thead>
                    <tr>
                        <th style="width: 20%;">Лид в облаке</th>
                        <th style="width: 12%;">Стадия</th>
                        <th style="width: 12%;">Компания</th>
                        <th style="width: 12%;">Контакт</th>
                        <th style="width: 12%;">Ответственный</th>
                        <th style="width: 5%;"></th>
                        <th style="width: 20%;">Лид в коробке</th>
                        <th style="width: 7%;">Статус</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cloudLeads as $cloud):
                    $cloudId = (int) $cloud['bitrix_id'];
                    $mapped = $mappings[$cloudId] ?? null;
                    $cloudCategoryId = (int) ($cloud['category_id'] ?? 0);
                    $cloudStatusId = (string) ($cloud['status_id'] ?? '');
                    $cloudCompanyId = (int) ($cloud['company_id'] ?? 0);
                    $cloudContactId = (int) ($cloud['contact_id'] ?? 0);
                    $assignedCloudId = (int) ($cloud['assigned_by_id'] ?? 0);
                    
                    // Стадия в коробке
                    $stageKey = $cloudCategoryId . '|' . $cloudStatusId;
                    $stageMapping = $stageMappings[$stageKey] ?? null;
                    $boxStatusId = $stageMapping['box_status_id'] ?? '';
                    
                    // Компания в коробке
                    $boxCompanyId = $companyMappings[$cloudCompanyId]['box_company_id'] ?? null;
                    
                    // Контакт в коробке
                    $boxContactId = $contactMappings[$cloudContactId]['box_contact_id'] ?? null;
                    
                    // Ответственный в коробке
                    $assignedBoxId = $userMappings[$assignedCloudId]['box_user_id'] ?? null;
                    
                    $isMigrated = $mapped && $mapped['box_lead_id'] > 0;
                    
                    // Название стадии
                    $stageName = $cloudStatusId;
                    foreach ($cloudStages as $stage) {
                        if ((int) $stage['category_id'] === $cloudCategoryId && $stage['status_id'] === $cloudStatusId) {
                            $stageName = $stage['name'] ?? $cloudStatusId;
                            break;
                        }
                    }
                ?>
                    <tr data-cloud-id="<?= $cloudId ?>">
                        <td>
                            <div class="person">
                                <strong><?= htmlspecialchars($cloud['title'] ?? 'Без названия', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                <small>ID <?= $cloudId ?></small>
                                <?php if (!empty($cloud['opportunity'])): ?>
                                    <small>· <?= number_format((float) $cloud['opportunity'], 0, ',', ' ') ?> <?= htmlspecialchars($cloud['currency'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span><?= htmlspecialchars($stageName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php if ($boxStatusId !== '' && $boxStatusId !== $cloudStatusId): ?>
                                <small style="color: var(--ok);">→ <?= htmlspecialchars($boxStatusId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                            <?php elseif ($boxStatusId === ''): ?>
                                <small style="color: var(--warn);">⚠️ не сопоставлена</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cloudCompanyId > 0): ?>
                                <small>ID <?= $cloudCompanyId ?></small>
                                <?php if ($boxCompanyId): ?>
                                    <small style="color: var(--ok);">→ <?= $boxCompanyId ?></small>
                                <?php else: ?>
                                    <small style="color: var(--warn);">⚠️ не перенесена</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cloudContactId > 0): ?>
                                <small>ID <?= $cloudContactId ?></small>
                                <?php if ($boxContactId): ?>
                                    <small style="color: var(--ok);">→ <?= $boxContactId ?></small>
                                <?php else: ?>
                                    <small style="color: var(--warn);">⚠️ не перенесён</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($assignedCloudId > 0): ?>
                                <small>ID <?= $assignedCloudId ?></small>
                                <?php if ($assignedBoxId): ?>
                                    <small style="color: var(--ok);">→ <?= $assignedBoxId ?></small>
                                <?php else: ?>
                                    <small style="color: var(--warn);">⚠️ не сопоставлен</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="empty">Не назначен</span>
                            <?php endif; ?>
                        </td>
                        <td class="arrow">→</td>
                        <td>
                            <?php if ($isMigrated): ?>
                                <div class="person" style="color: var(--ok);">
                                    <strong><?= htmlspecialchars($mapped['title'] ?? $cloud['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                    <small>ID <?= $mapped['box_lead_id'] ?></small>
                                </div>
                            <?php else: ?>
                                <span class="empty">Будет создан</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isMigrated): ?>
                                <span class="badge" style="background: var(--ok); color: white;">✅ Перенесён</span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--muted); color: white;">Ожидает</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <?php if (empty($cloudLeads)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--muted);">
                            Нет лидов в облаке. Нажмите «Загрузить лиды».
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($cloudLeads)): ?>
        <div class="card" style="margin-top: 1rem; background: var(--bg);">
            <p style="margin: 0; color: var(--muted);">
                💡 <strong>Как это работает:</strong>
                <br>
                1. Нажмите <strong>«Загрузить стадии»</strong> — стадии загрузятся с обоих порталов и сопоставятся по названию.
                <br>
                2. Нажмите <strong>«Загрузить лиды»</strong> — лиды загрузятся с обоих порталов.
                <br>
                3. Нажмите <strong>«Перенести лиды»</strong> — каждый лид из облака будет создан в коробке.
                <br>
                4. Лиды привязываются к стадиям, компаниям, контактам и ответственным автоматически.
                <br>
                5. <strong>Важно:</strong> Сначала перенесите компании и контакты, чтобы лиды могли привязаться.
            </p>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</section>