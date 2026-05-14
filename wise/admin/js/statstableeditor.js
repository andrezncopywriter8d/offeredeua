// Stats Table Editor functionality
const qs = (sel, ctx = document) => ctx.querySelector(sel);
const qsa = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

const MAX_ORDERBY_RULES = 3;

function initializeStatsTableEditor(availableColumns, selectedMetrics, availableDimensions, selectedDimensions, tableName, saveUrl, existingFilters, existingOrderby) {
    const MAX_GROUPBY_SELECTIONS = 3;

    // Initialize Sortable.js for both lists
    initializeSortable('metricsColumns', 'metrics');
    initializeSortable('dimensionsColumns', 'dimensions');

    // Set table name if provided
    const tableNameInput = document.getElementById('tableName');
    if (tableName) tableNameInput.value = tableName;

    // Separate param.* dimensions from regular ones
    const regularDimensions = selectedDimensions.filter(d => !d.startsWith('param.'));
    const paramDimensions = selectedDimensions.filter(d => d.startsWith('param.'));

    // Add columns (metrics get sort toggles)
    addColumnsToList('metricsColumns', selectedMetrics, availableColumns, existingOrderby);
    addColumnsToList('dimensionsColumns', regularDimensions, availableDimensions);

    // Restore saved param dimensions as items in the list
    for (const pd of paramDimensions) {
        addParamItem('dimensionsColumns', pd.substring(6));
    }

    // Keep exact saved GroupBy order (including param.* positions)
    reorderItemsByFields('dimensionsColumns', selectedDimensions);

    // Enforce dimension limit on init (disables + Param button if already at max)
    enforceDimensionLimit(MAX_GROUPBY_SELECTIONS);

    // Initialize filters
    initializeFilters(existingFilters);

    // Setup metrics select/deselect buttons
    setupSelectButtons('selectAllMetrics', 'deselectAllMetrics', 'metricsColumns');

    // Metrics checkbox change → update save button + reset sort toggle if unchecked
    document.getElementById('metricsColumns').addEventListener('change', (e) => {
        if (e.target.matches('input[type="checkbox"]')) {
            updateSaveButtonState();
            if (!e.target.checked) {
                const item = e.target.closest('.column-item');
                const btn = item?.querySelector('.sort-toggle');
                if (btn) setSortToggleState(btn, 'none');
            }
            updateSortToggleAvailability();
        }
    });

    // Dimensions checkbox change → enforce max selections + update save button
    document.getElementById('dimensionsColumns').addEventListener('change', (e) => {
        if (!e.target.matches('input[type="checkbox"]')) return;
        enforceDimensionLimit(MAX_GROUPBY_SELECTIONS);
        updateSaveButtonState();
    });

    // Add param dimension button (event delegation for jquery-modal compatibility)
    document.removeEventListener('click', handleAddParamDimension);
    document.addEventListener('click', handleAddParamDimension);

    // Table name input → update save button
    tableNameInput.addEventListener('input', () => updateSaveButtonState());

    // Initial button state
    updateSaveButtonState();

    // Save handler
    document.getElementById('saveTableBtn').addEventListener('click', async () => {
        const name = tableNameInput.value.trim();
        if (!name) { alert('Please enter a table name'); return; }

        const columns = getSelectedItems('metricsColumns');
        if (!columns.length) { alert('Please select at least one metric column'); return; }

        const groupby = getSelectedItems('dimensionsColumns');
        if (!groupby.length) { alert('Please select at least one dimension for grouping'); return; }
        if (groupby.length > MAX_GROUPBY_SELECTIONS) {
            alert(`You can select at most ${MAX_GROUPBY_SELECTIONS} dimensions for grouping`);
            return;
        }

        const filters = collectFilters();
        const orderby = collectOrderby();

        try {
            const res = await fetch(saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, columns, groupby, filters, orderby }),
            });
            if (!res.ok) throw new Error('Network response was not ok');

            const data = await res.json();
            if (data.error) throw new Error(data.msg);
            window.location.reload();
        } catch (err) {
            alert('Error saving table: ' + err.message);
        }
    });

    // Cancel handler — jquery-modal is still used for the modal itself
    document.getElementById('cancelTableBtn').addEventListener('click', () => {
        jQuery.modal.close();
    });
}

async function deleteStatsTable(tableName, deleteUrl) {
    if (!confirm(`Are you sure you want to delete table "${tableName}"?`)) return;

    try {
        const res = await fetch(deleteUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: tableName }),
        });
        const data = await res.json();
        if (data.error) throw new Error(data.msg);

        const url = new URL(window.location.href);
        if (url.searchParams.has('table')) {
            url.searchParams.delete('table');
            window.location.href = url.toString();
        } else {
            window.location.reload();
        }
    } catch (err) {
        alert('Error deleting table: ' + err.message);
    }
}

// ── Helper functions ──

function addColumnsToList(containerId, selectedItems, columns, orderbyRules) {
    const container = document.getElementById(containerId);
    container.innerHTML = '';
    const isMetrics = containerId === 'metricsColumns';

    for (const column of columns) {
        const field = typeof column === 'string' ? column : column.field;
        const title = typeof column === 'string'
            ? formatColumnName(column)
            : (column.title || formatColumnName(column.field));
        const isSelected = selectedItems.some(item =>
            (typeof item === 'string' ? item : item.field) === field
        );

        const div = document.createElement('div');
        div.className = 'column-item';
        div.dataset.field = field;

        let sortToggleHtml = '';
        if (isMetrics) {
            const rule = orderbyRules?.find(r => r.field === field);
            const state = rule ? rule.dir : 'none';
            const activeClass = state !== 'none' ? ' sort-active' : '';
            sortToggleHtml = `<button type="button" class="sort-toggle${activeClass}" data-sort="${state}" title="Sort">${getSortToggleIcon(state)}</button>`;
        }

        div.innerHTML = `
            <span class="drag-handle">☰</span>
            <input type="checkbox" ${isSelected ? 'checked' : ''}>
            <span class="column-label">${title}</span>
            ${sortToggleHtml}
        `;

        if (isMetrics) {
            const btn = div.querySelector('.sort-toggle');
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const cb = div.querySelector('input[type="checkbox"]');
                if (!cb.checked) return;
                cycleSortToggle(btn);
            });
        }

        container.appendChild(div);
    }

    if (isMetrics) updateSortToggleAvailability();
}

function setupSelectButtons(selectAllId, deselectAllId, containerId) {
    document.getElementById(selectAllId).addEventListener('click', () => {
        for (const cb of qsa(`#${containerId} input[type="checkbox"]`)) {
            if (!cb.disabled) {
                cb.checked = true;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });

    document.getElementById(deselectAllId).addEventListener('click', () => {
        document.querySelectorAll(`#${containerId} .param-item`).forEach(el => el.remove());
        for (const cb of qsa(`#${containerId} input[type="checkbox"]`)) {
            cb.checked = false;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

function getSelectedItems(containerId) {
    return qsa(`#${containerId} .column-item`)
        .filter(el => el.querySelector('input').checked)
        .map(el => el.dataset.field);
}

function handleAddParamDimension(e) {
    if (!e.target.closest('#addParamDimension')) return;
    const MAX_GROUPBY_SELECTIONS = 3;
    const total = qsa('#dimensionsColumns input[type="checkbox"]:checked').length;
    if (total >= MAX_GROUPBY_SELECTIONS) return;
    const name = prompt('URL param name:');
    if (!name || !name.trim()) return;
    const clean = name.trim().replace(/[^a-zA-Z0-9_]/g, '');
    if (!clean) return;
    addParamItem('dimensionsColumns', clean);
    enforceDimensionLimit(MAX_GROUPBY_SELECTIONS);
    updateSaveButtonState();
}

function updateSaveButtonState() {
    const metricsSelected = qsa('#metricsColumns input[type="checkbox"]').some(cb => cb.checked);
    const dimensionsSelected = qsa('#dimensionsColumns input[type="checkbox"]').some(cb => cb.checked);
    const name = document.getElementById('tableName').value.trim();

    document.getElementById('saveTableBtn').disabled = !metricsSelected || !dimensionsSelected || !name;
}

function formatColumnName(field) {
    return field.split(/(?=[A-Z])/).join(' ').toLowerCase().replace(/\b\w/g, l => l.toUpperCase());
}

function initializeSortable(containerId, group) {
    new Sortable(document.getElementById(containerId), {
        animation: 150,
        group,
    });
}

function reorderItemsByFields(containerId, orderedFields) {
    const container = document.getElementById(containerId);
    if (!container || !Array.isArray(orderedFields) || orderedFields.length === 0) return;

    const byField = new Map();
    qsa(`#${containerId} .column-item`).forEach((el) => {
        byField.set(el.dataset.field, el);
    });

    orderedFields.forEach((field) => {
        const el = byField.get(field);
        if (el) {
            container.appendChild(el);
            byField.delete(field);
        }
    });
}

// ── Sort toggle (3-state) on metric columns ──

function getSortToggleIcon(state) {
    if (state === 'desc') return '▼';
    if (state === 'asc') return '▲';
    return '▼';
}

function setSortToggleState(btn, state) {
    btn.dataset.sort = state;
    btn.textContent = getSortToggleIcon(state);
    btn.className = 'sort-toggle' + (state !== 'none' ? ' sort-active' : '');
}

function cycleSortToggle(btn) {
    const current = btn.dataset.sort || 'none';
    if (current === 'none') {
        const activeCount = qsa('#metricsColumns .sort-toggle.sort-active').length;
        if (activeCount >= MAX_ORDERBY_RULES) return;
        setSortToggleState(btn, 'desc');
    } else if (current === 'desc') {
        setSortToggleState(btn, 'asc');
    } else {
        setSortToggleState(btn, 'none');
    }
    updateSortToggleAvailability();
}

function updateSortToggleAvailability() {
    const activeCount = qsa('#metricsColumns .sort-toggle.sort-active').length;
    for (const btn of qsa('#metricsColumns .sort-toggle')) {
        const item = btn.closest('.column-item');
        const cb = item.querySelector('input[type="checkbox"]');
        const isActive = btn.classList.contains('sort-active');
        btn.style.visibility = cb.checked ? 'visible' : 'hidden';
        btn.style.opacity = (!isActive && activeCount >= MAX_ORDERBY_RULES) ? '0.3' : '';
        btn.style.cursor = (!isActive && activeCount >= MAX_ORDERBY_RULES) ? 'not-allowed' : 'pointer';
    }
}

function collectOrderby() {
    const rules = [];
    for (const btn of qsa('#metricsColumns .sort-toggle.sort-active')) {
        const item = btn.closest('.column-item');
        rules.push({ field: item.dataset.field, dir: btn.dataset.sort });
    }
    return rules;
}

// ── Dimension limit helpers ──

function enforceDimensionLimit(max) {
    const total = qsa('#dimensionsColumns input[type="checkbox"]:checked').length;
    const allBoxes = qsa('#dimensionsColumns input[type="checkbox"]');
    for (const cb of allBoxes) {
        cb.disabled = !cb.checked && total >= max;
    }
    const addBtn = document.getElementById('addParamDimension');
    if (addBtn) {
        addBtn.disabled = total >= max;
        addBtn.style.opacity = total >= max ? '0.5' : '';
    }
}
