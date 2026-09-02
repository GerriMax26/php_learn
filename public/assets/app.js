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
