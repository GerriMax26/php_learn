(() => {
    const page = document.querySelector('[data-page="mapping"]');
    if (!page) {
        return;
    }

    const csrf = page.dataset.csrf;
    const flash = document.getElementById('flash');
    const table = document.getElementById('map-table');
    const search = document.getElementById('search');
    const unmappedOnly = document.getElementById('unmapped-only');
    const stat = document.getElementById('stat');
    const syncBtn = document.getElementById('sync-btn');
    const saveBtn = document.getElementById('save-btn');
    const autoHint = document.getElementById('auto-hint');

    function showFlash(text, isError = false) {
        if (!flash) {
            return;
        }
        flash.hidden = false;
        flash.textContent = text;
        flash.classList.toggle('error', isError);
    }

    function rows() {
        return table ? [...table.querySelectorAll('tbody tr')] : [];
    }

    function updateHints() {
        const used = new Map();
        rows().forEach((row) => {
            const select = row.querySelector('.box-select');
            const hint = row.querySelector('.row-hint');
            const boxId = select.value;
            row.classList.remove('row-ok', 'row-warn', 'row-dup');
            hint.textContent = '';

            if (!boxId) {
                return;
            }

            if (used.has(boxId)) {
                row.classList.add('row-dup');
                used.get(boxId).classList.add('row-dup');
                hint.textContent = 'Этот пользователь коробки уже выбран в другой строке';
                used.get(boxId).querySelector('.row-hint').textContent = 'Дубликат назначения';
            } else {
                used.set(boxId, row);
            }

            const option = select.selectedOptions[0];
            const cloudEmail = row.dataset.email || '';
            const boxEmail = option?.dataset.email || '';
            if (cloudEmail && boxEmail && cloudEmail === boxEmail) {
                row.classList.add('row-ok');
                if (!hint.textContent) {
                    hint.textContent = 'Email совпадает';
                }
            } else if (cloudEmail && boxEmail && cloudEmail !== boxEmail) {
                row.classList.add('row-warn');
                if (!hint.textContent) {
                    hint.textContent = 'Email отличается';
                }
            }
        });

        const mapped = rows().filter((row) => row.querySelector('.box-select').value).length;
        if (stat) {
            stat.textContent = `Сопоставлено ${mapped} из ${rows().length}`;
        }
        filterRows();
    }

    function filterRows() {
        const q = (search?.value || '').trim().toLowerCase();
        const only = Boolean(unmappedOnly?.checked);
        rows().forEach((row) => {
            const hay = row.dataset.search || '';
            const mapped = Boolean(row.querySelector('.box-select').value);
            const visible = (!q || hay.includes(q)) && (!only || !mapped);
            row.classList.toggle('is-hidden', !visible);
        });
    }

    function collectPairs() {
        return rows().map((row) => ({
            cloud_user_id: Number(row.dataset.cloudId),
            box_user_id: row.querySelector('.box-select').value || null,
        }));
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': csrf,
            },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || 'Ошибка запроса');
        }
        return data;
    }

    table?.addEventListener('change', (event) => {
        if (event.target.classList.contains('box-select')) {
            updateHints();
        }
    });
    search?.addEventListener('input', filterRows);
    unmappedOnly?.addEventListener('change', filterRows);

    syncBtn?.addEventListener('click', async () => {
        syncBtn.disabled = true;
        try {
            const data = await postJson('/sync', {});
            showFlash(`Загружено: облако ${data.counts.cloud}, коробка ${data.counts.box}. Страница будет обновлена.`);
            setTimeout(() => window.location.reload(), 600);
        } catch (error) {
            showFlash(error.message, true);
            syncBtn.disabled = false;
        }
    });

    saveBtn?.addEventListener('click', async () => {
        if (document.querySelector('.row-dup')) {
            showFlash('Уберите дубликаты назначений на коробке перед сохранением.', true);
            return;
        }
        saveBtn.disabled = true;
        try {
            const data = await postJson('/mappings', { pairs: collectPairs(), csrf });
            showFlash(`Сохранено соответствий: ${data.saved}`);
        } catch (error) {
            showFlash(error.message, true);
        } finally {
            saveBtn.disabled = false;
        }
    });

    autoHint?.addEventListener('click', () => {
        rows().forEach((row) => {
            const select = row.querySelector('.box-select');
            if (select.value) {
                return;
            }
            const email = row.dataset.email;
            if (!email) {
                return;
            }
            const match = [...select.options].find((opt) => opt.value && opt.dataset.email === email);
            if (match) {
                const already = rows().some((other) => other !== row && other.querySelector('.box-select').value === match.value);
                if (!already) {
                    select.value = match.value;
                }
            }
        });
        updateHints();
    });

    updateHints();
})();
// ============================================
// РАБОТА С КОМПАНИЯМИ
// ============================================

(function() {
    const page = document.querySelector('[data-page="companies"]');
    if (!page) {
        return;
    }

    const csrf = page.dataset.csrf;
    const flash = document.getElementById('flash');
    const syncBtn = document.getElementById('sync-companies-btn');
    const migrateBtn = document.getElementById('migrate-btn');

    function showFlash(text, isError = false) {
        if (!flash) return;
        flash.hidden = false;
        flash.textContent = text;
        flash.classList.toggle('error', isError);
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': csrf,
            },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || 'Ошибка запроса');
        }
        return data;
    }

    // Синхронизация компаний
    syncBtn?.addEventListener('click', async () => {
        syncBtn.disabled = true;
        syncBtn.textContent = 'Загрузка...';
        try {
            const data = await postJson('/sync-companies', {});
            showFlash(
                `✅ Загружено компаний: облако ${data.counts.cloud}, коробка ${data.counts.box}. Страница будет обновлена.`
            );
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            showFlash('❌ ' + error.message, true);
            syncBtn.disabled = false;
            syncBtn.textContent = 'Загрузить компании';
        }
    });

    // Перенос компаний
    migrateBtn?.addEventListener('click', async () => {
        // Проверяем, есть ли компании для переноса
        const rows = document.querySelectorAll('#company-table tbody tr');
        let hasUnmigrated = false;
        rows.forEach(row => {
            const status = row.querySelector('td:last-child .badge');
            if (status && status.textContent.trim() === 'Ожидает') {
                hasUnmigrated = true;
            }
        });

        if (!hasUnmigrated) {
            showFlash('ℹ️ Нет компаний для переноса (все уже перенесены).', false);
            return;
        }

        if (!confirm('Перенести все компании из облака в коробку? Ответственные будут назначены автоматически.')) {
            return;
        }

        migrateBtn.disabled = true;
        migrateBtn.textContent = 'Перенос...';
        try {
            const data = await postJson('/migrate-companies', {});
            const result = data.result;
            
            let msg = `✅ Перенесено: ${result.created}`;
            if (result.skipped > 0) msg += `, пропущено (уже есть): ${result.skipped}`;
            if (result.errors > 0) msg += `, ❌ ошибок: ${result.errors}`;
            
            showFlash(msg, result.errors > 0);
            
            if (result.errors > 0) {
                console.error('Детали ошибок:');
                result.details?.filter(d => d.includes('Ошибка')).forEach(d => console.error(d));
            }
            
            if (result.created > 0 || result.errors === 0) {
                setTimeout(() => window.location.reload(), 1200);
            } else {
                migrateBtn.disabled = false;
                migrateBtn.textContent = 'Перенести компании';
            }
        } catch (error) {
            showFlash('❌ ' + error.message, true);
            migrateBtn.disabled = false;
            migrateBtn.textContent = 'Перенести компании';
        }
    });

    // Если есть flash-сообщение при загрузке страницы, показываем его
    const flashMessage = page.dataset.flash;
    if (flashMessage) {
        showFlash(flashMessage);
    }
})();

// ============================================
// РАБОТА С КОНТАКТАМИ
// ============================================

(function() {
    const page = document.querySelector('[data-page="contacts"]');
    if (!page) {
        return;
    }

    const csrf = page.dataset.csrf;
    const flash = document.getElementById('flash');
    const syncBtn = document.getElementById('sync-contacts-btn');
    const migrateBtn = document.getElementById('migrate-btn');

    function showFlash(text, isError = false) {
        if (!flash) return;
        flash.hidden = false;
        flash.textContent = text;
        flash.classList.toggle('error', isError);
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': csrf,
            },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || 'Ошибка запроса');
        }
        return data;
    }

    syncBtn?.addEventListener('click', async () => {
        syncBtn.disabled = true;
        syncBtn.textContent = 'Загрузка...';
        try {
            const data = await postJson('/sync-contacts', {});
            showFlash(
                `✅ Загружено контактов: облако ${data.counts.cloud}, коробка ${data.counts.box}. Страница будет обновлена.`
            );
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            showFlash('❌ ' + error.message, true);
            syncBtn.disabled = false;
            syncBtn.textContent = 'Загрузить контакты';
        }
    });

    migrateBtn?.addEventListener('click', async () => {
        // Проверяем, есть ли контакты для переноса
        const rows = document.querySelectorAll('#contact-table tbody tr');
        let hasUnmigrated = false;
        rows.forEach(row => {
            const status = row.querySelector('td:last-child .badge');
            if (status && status.textContent.trim() === 'Ожидает') {
                hasUnmigrated = true;
            }
        });

        if (!hasUnmigrated) {
            showFlash('ℹ️ Нет контактов для переноса (все уже перенесены).', false);
            return;
        }

        if (!confirm('Перенести все контакты из облака в коробку?')) {
            return;
        }

        migrateBtn.disabled = true;
        migrateBtn.textContent = 'Перенос...';
        try {
            const data = await postJson('/migrate-contacts', {});
            const result = data.result;
            
            let msg = `✅ Перенесено: ${result.created}`;
            if (result.skipped > 0) msg += `, пропущено: ${result.skipped}`;
            if (result.errors > 0) msg += `, ❌ ошибок: ${result.errors}`;
            
            showFlash(msg, result.errors > 0);
            
            if (result.errors > 0) {
                console.error('Детали ошибок:');
                result.details?.filter(d => d.includes('Ошибка')).forEach(d => console.error(d));
            }
            
            if (result.created > 0 || result.errors === 0) {
                setTimeout(() => window.location.reload(), 1200);
            } else {
                migrateBtn.disabled = false;
                migrateBtn.textContent = 'Перенести контакты';
            }
        } catch (error) {
            showFlash('❌ ' + error.message, true);
            migrateBtn.disabled = false;
            migrateBtn.textContent = 'Перенести контакты';
        }
    });
})();

// ============================================
// РАБОТА С ЛИДАМИ
// ============================================

(function() {
    const page = document.querySelector('[data-page="leads"]');
    if (!page) {
        return;
    }

    const csrf = page.dataset.csrf;
    const flash = document.getElementById('flash');
    const syncStagesBtn = document.getElementById('sync-stages-btn');
    const syncLeadsBtn = document.getElementById('sync-leads-btn');
    const migrateBtn = document.getElementById('migrate-btn');

    function showFlash(text, isError = false) {
        if (!flash) return;
        flash.hidden = false;
        flash.textContent = text;
        flash.classList.toggle('error', isError);
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': csrf,
            },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || 'Ошибка запроса');
        }
        return data;
    }

    // Синхронизация стадий
    syncStagesBtn?.addEventListener('click', async () => {
        syncStagesBtn.disabled = true;
        syncStagesBtn.textContent = 'Загрузка...';
        try {
            const data = await postJson('/sync-stages', {});
            showFlash(
                `✅ Загружено стадий: облако ${data.counts.cloud}, коробка ${data.counts.box}. Сопоставление выполнено автоматически. Страница будет обновлена.`
            );
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            showFlash('❌ ' + error.message, true);
            syncStagesBtn.disabled = false;
            syncStagesBtn.textContent = 'Загрузить стадии';
        }
    });

    // Синхронизация лидов
    syncLeadsBtn?.addEventListener('click', async () => {
        syncLeadsBtn.disabled = true;
        syncLeadsBtn.textContent = 'Загрузка...';
        try {
            const data = await postJson('/sync-leads', {});
            showFlash(
                `✅ Загружено лидов: облако ${data.counts.cloud}, коробка ${data.counts.box}. Страница будет обновлена.`
            );
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            showFlash('❌ ' + error.message, true);
            syncLeadsBtn.disabled = false;
            syncLeadsBtn.textContent = 'Загрузить лиды';
        }
    });

    // Перенос лидов
    migrateBtn?.addEventListener('click', async () => {
        // Проверяем, есть ли лиды для переноса
        const rows = document.querySelectorAll('#lead-table tbody tr');
        let hasUnmigrated = false;
        let missingStages = false;
        let missingCompanies = false;
        let missingContacts = false;

        rows.forEach(row => {
            const status = row.querySelector('td:last-child .badge');
            if (status && status.textContent.trim() === 'Ожидает') {
                hasUnmigrated = true;
                
                // Проверяем предупреждения
                const cells = row.querySelectorAll('td');
                // Стадия (индекс 1)
                const stageCell = cells[1];
                if (stageCell && stageCell.textContent.includes('не сопоставлена')) {
                    missingStages = true;
                }
                // Компания (индекс 2)
                const companyCell = cells[2];
                if (companyCell && companyCell.textContent.includes('не перенесена')) {
                    missingCompanies = true;
                }
                // Контакт (индекс 3)
                const contactCell = cells[3];
                if (contactCell && contactCell.textContent.includes('не перенесён')) {
                    missingContacts = true;
                }
            }
        });

        if (!hasUnmigrated) {
            showFlash('ℹ️ Нет лидов для переноса (все уже перенесены).', false);
            return;
        }

        // Предупреждения
        let warnings = [];
        if (missingStages) warnings.push('некоторые стадии не сопоставлены');
        if (missingCompanies) warnings.push('некоторые компании не перенесены');
        if (missingContacts) warnings.push('некоторые контакты не перенесены');

        if (warnings.length > 0) {
            if (!confirm(
                `⚠️ Внимание! Есть проблемы:\n• ${warnings.join('\n• ')}\n\n` +
                `Рекомендуется сначала перенести компании и контакты, а также сопоставить стадии.\n\n` +
                `Продолжить перенос лидов?`
            )) {
                return;
            }
        } else {
            if (!confirm('Перенести все лиды из облака в коробку?')) {
                return;
            }
        }

        migrateBtn.disabled = true;
        migrateBtn.textContent = 'Перенос...';
        try {
            const data = await postJson('/migrate-leads', {});
            const result = data.result;
            
            let msg = `✅ Перенесено лидов: ${result.created}`;
            if (result.skipped > 0) msg += `, пропущено: ${result.skipped}`;
            if (result.errors > 0) msg += `, ❌ ошибок: ${result.errors}`;
            
            showFlash(msg, result.errors > 0);
            
            if (result.errors > 0) {
                console.error('Детали ошибок:');
                result.details?.filter(d => d.includes('Ошибка')).forEach(d => console.error(d));
            }
            
            if (result.created > 0 || result.errors === 0) {
                setTimeout(() => window.location.reload(), 1200);
            } else {
                migrateBtn.disabled = false;
                migrateBtn.textContent = 'Перенести лиды';
            }
        } catch (error) {
            showFlash('❌ ' + error.message, true);
            migrateBtn.disabled = false;
            migrateBtn.textContent = 'Перенести лиды';
        }
    });
})();
