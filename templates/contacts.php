<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $cloudContacts */
/** @var list<array<string, mixed>> $boxContacts */
/** @var array<int, array<string, mixed>> $mappings */
/** @var array<int, array<string, mixed>> $userMappings */
/** @var array<int, array<string, mixed>> $companyMappings */

$view = 'contacts';
?>
<section class="page" data-page="contacts" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <header class="page-head row">
        <div>
            <h1>📇 Миграция контактов</h1>
            <p>
                Облако: <strong><?= count($cloudContacts) ?></strong>
                · Коробка: <strong><?= count($boxContacts) ?></strong>
            </p>
        </div>
        <div class="actions">
            <button type="button" class="btn" id="sync-contacts-btn">Загрузить контакты</button>
            <button type="button" class="btn primary" id="migrate-btn">Перенести контакты</button>
        </div>
    </header>

    <div id="flash" class="flash" hidden></div>

    <?php if ($cloudContacts === [] && $boxContacts === []): ?>
        <div class="card empty">
            <p>Контакты ещё не загружены. Нажмите <strong>«Загрузить контакты»</strong>.</p>
            <p style="margin-top: 0.5rem; color: var(--muted);">
                💡 Контакты будут привязаны к компаниям, а ответственные назначены автоматически.
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap card">
            <table class="map-table" id="contact-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Контакт в облаке</th>
                        <th style="width: 15%;">Компания</th>
                        <th style="width: 15%;">Ответственный</th>
                        <th style="width: 5%;"></th>
                        <th style="width: 25%;">Контакт в коробке</th>
                        <th style="width: 10%;">Статус</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cloudContacts as $cloud):
                    $cloudId = (int) $cloud['bitrix_id'];
                    $mapped = $mappings[$cloudId] ?? null;
                    $cloudCompanyId = (int) ($cloud['company_id'] ?? 0);
                    $assignedCloudId = (int) ($cloud['assigned_by_id'] ?? 0);
                    
                    // Компания в коробке
                    $boxCompanyId = $companyMappings[$cloudCompanyId]['box_company_id'] ?? null;
                    
                    // Ответственный в коробке
                    $assignedBoxId = $userMappings[$assignedCloudId]['box_user_id'] ?? null;
                    
                    $isMigrated = $mapped && $mapped['box_contact_id'] > 0;
                ?>
                    <tr data-cloud-id="<?= $cloudId ?>">
                        <td>
                            <div class="person">
                                <strong><?= htmlspecialchars($cloud['full_name'] ?? 'Без имени', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                <small>ID <?= $cloudId ?></small>
                                <?php if (!empty($cloud['email'])): ?>
                                    <small>· <?= htmlspecialchars($cloud['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($cloudCompanyId > 0): ?>
                                <?php 
                                    // Пытаемся найти компанию в кеше
                                    $companyName = 'Компания #' . $cloudCompanyId;
                                ?>
                                <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            <?php else: ?>
                                <span class="empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($assignedCloudId > 0): ?>
                                <?= htmlspecialchars('Пользователь #' . $assignedCloudId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            <?php else: ?>
                                <span class="empty">Не назначен</span>
                            <?php endif; ?>
                        </td>
                        <td class="arrow">→</td>
                        <td>
                            <?php if ($isMigrated): ?>
                                <div class="person" style="color: var(--ok);">
                                    <strong><?= htmlspecialchars($mapped['full_name'] ?? $cloud['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                    <small>ID <?= $mapped['box_contact_id'] ?></small>
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
                
                <?php if (empty($cloudContacts)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: var(--muted);">
                            Нет контактов в облаке. Нажмите «Загрузить контакты».
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($cloudContacts)): ?>
        <div class="card" style="margin-top: 1rem; background: var(--bg);">
            <p style="margin: 0; color: var(--muted);">
                💡 <strong>Как это работает:</strong>
                <br>
                1. Нажмите <strong>«Загрузить контакты»</strong> — данные загрузятся с обоих порталов.
                <br>
                2. Нажмите <strong>«Перенести контакты»</strong> — каждый контакт из облака будет создан в коробке.
                <br>
                3. Контакты привязываются к компаниям (если компания перенесена).
                <br>
                4. Ответственный назначается автоматически, если пользователь сопоставлен.
            </p>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</section>