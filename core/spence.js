/**
 * SPENCE shared UI helpers (loaded in <head> so inline page scripts can use them).
 */

function escHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Focus the first visible [data-autofocus] field whenever any modal finishes opening.
document.addEventListener('shown.bs.modal', event => {
    const field = [...event.target.querySelectorAll('[data-autofocus]')].find(el => el.offsetParent !== null);
    if (!field) return;
    field.focus();
    if (typeof field.select === 'function' && field.value) field.select();
});

/**
 * Type-to-search product picker. The list stays empty until something is typed, matches
 * product names only, and supports ↑/↓ + Enter so a pick never needs the mouse.
 *   input    — the search <input>
 *   list     — container the results render into
 *   products — array of { id, name, ... }
 *   onSelect — called with the chosen product
 *   meta     — optional fn(product) returning extra HTML shown on the right of each row
 *   limit    — max rows rendered (default 50)
 * Returns { refresh(), clear() }.
 */
function attachProductSearch({ input, list, products, onSelect, meta = null, limit = 50 }) {
    let matches = [];
    let active = 0;

    function rank(name, q) {
        if (name.startsWith(q)) return 0;
        if (name.split(/[\s\-\/(]+/).some(word => word.startsWith(q.split(' ')[0]))) return 1;
        return 2;
    }

    function render() {
        const q = input.value.trim().toLowerCase().replace(/\s+/g, ' ');
        if (!q) {
            matches = [];
            list.innerHTML = '';
            list.style.display = 'none';
            return;
        }
        const tokens = q.split(' ');
        matches = products
            .filter(p => { const name = p.name.toLowerCase(); return tokens.every(t => name.includes(t)); })
            .map(p => ({ p, r: rank(p.name.toLowerCase(), q) }))
            .sort((a, b) => a.r - b.r || a.p.name.localeCompare(b.p.name))
            .slice(0, limit)
            .map(m => m.p);
        active = 0;
        list.style.display = '';
        if (!matches.length) {
            list.innerHTML = '<div class="product-search-empty">No products match.</div>';
            return;
        }
        list.innerHTML = matches.map((p, i) => `
            <div class="product-search-item${i === active ? ' active' : ''}" data-index="${i}">
                <span class="fw-bold text-white text-truncate">${escHtml(p.name)}</span>
                ${meta ? `<span class="flex-shrink-0">${meta(p)}</span>` : ''}
            </div>`).join('');
    }

    function highlight(index) {
        const rows = list.querySelectorAll('.product-search-item');
        if (!rows.length) return;
        active = (index + rows.length) % rows.length;
        rows.forEach((row, i) => row.classList.toggle('active', i === active));
        rows[active].scrollIntoView({ block: 'nearest' });
    }

    function choose(index) {
        const product = matches[index];
        if (!product) return;
        list.style.display = 'none';
        onSelect(product);
    }

    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', render);
    input.addEventListener('focus', () => { if (input.value.trim() && !matches.length) render(); });
    input.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') { event.preventDefault(); highlight(active + 1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(active - 1); }
        else if (event.key === 'Enter') { event.preventDefault(); choose(active); }
    });
    // mousedown (not click) so the pick lands before the input's blur handlers run
    list.addEventListener('mousedown', event => {
        const row = event.target.closest('.product-search-item');
        if (!row) return;
        event.preventDefault();
        choose(parseInt(row.dataset.index, 10));
    });

    render();
    return {
        refresh: render,
        clear() { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); },
    };
}

/**
 * Merge review cards: each pair's products side by side (macros, unit, use) with a choice of
 * which name and macros survive. The target always survives with its id and unit.
 *   container    — element the cards render into
 *   pairs        — [{ source_id, target_id, source_name, reason, source, target }], where
 *                  source/target are productMergePreview() rows
 *   sourceLabel, targetLabel — column headings
 * Returns { apply({ dismissUnticked }) } which merges ticked pairs and resolves to failure messages.
 */
function mergeReview(container, pairs, { sourceLabel = 'Merge this', targetLabel = 'Into this' } = {}) {
    const state = pairs.map(p => ({ ...p, active: !!p.active, name: 'target', macros: 'target' }));
    const num = n => (Math.round(n * 10) / 10).toString();
    const unit = p => p.base_unit === 'ea' ? `each (${num(p.weight_per_ea * 1000)} g)` : `per ${p.base_unit}`;
    const macros = p => `${Math.round(p.kj_per_100)} kJ · P ${num(p.protein_per_100)} · F ${num(p.fat_per_100)} · C ${num(p.carb_per_100)}`;
    const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
    const use = p => [p.stock_qty ? `${num(p.stock_qty)} ${p.base_unit} in stock` : 'none in stock',
        plural(p.log_count, 'log'), p.recipe_count ? plural(p.recipe_count, 'recipe') : ''].filter(Boolean).join(' · ');
    const off = (x, y, floor) => Math.abs(x - y) > Math.max(0.15 * Math.max(x, y), floor);
    const differ = (a, b) => off(a.kj_per_100, b.kj_per_100, 40) || ['protein_per_100', 'fat_per_100', 'carb_per_100'].some(k => off(a[k], b[k], 1));

    function render() {
        container.innerHTML = state.map((m, i) => {
            const s = m.source, t = m.target, warn = differ(s, t) ? 'merge-differ' : '';
            const choice = (field, value, label) => `
                <label class="merge-choice ${m[field] === value ? 'chosen' : ''}">
                    <input type="radio" name="${field}-${i}" data-i="${i}" data-field="${field}" value="${value}" ${m[field] === value ? 'checked' : ''}>
                    <span>${label}</span>
                </label>`;
            const unitNote = s.base_unit === t.base_unit ? '' : `<div class="small mt-2" style="color:#ff9800;"><i class="bi bi-arrow-left-right me-1"></i>Different units: stock converts to ${escHtml(unit(t))}${s.base_unit === 'ea' ? ` at ${num(s.weight_per_ea * 1000)} g each` : ''}.</div>`;
            return `
            <div class="merge-review ${m.active ? 'active' : ''}">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <input class="form-check-input m-0" type="checkbox" id="merge-${i}" data-i="${i}" ${m.active ? 'checked' : ''} style="width:22px;height:22px;flex-shrink:0;">
                    <label for="merge-${i}" class="fw-bold flex-grow-1">Same product</label>
                    ${m.reason ? `<span class="badge bg-secondary opacity-75 small">${escHtml(m.reason)}</span>` : ''}
                </div>
                <div class="merge-grid">
                    <div class="merge-gap"></div>
                    <div class="merge-head">${escHtml(sourceLabel)}</div>
                    <div class="merge-head">${escHtml(targetLabel)}</div>
                    <div class="merge-label">Name</div>
                    ${choice('name', 'source', escHtml(s.name))}
                    ${choice('name', 'target', escHtml(t.name))}
                    <div class="merge-label">Macros <span class="fw-normal">/100 g</span></div>
                    ${choice('macros', 'source', `<span class="${warn}">${macros(s)}</span>`)}
                    ${choice('macros', 'target', `<span class="${warn}">${macros(t)}</span>`)}
                    <div class="merge-gap"></div>
                    <div class="merge-span">${choice('macros', 'blend', 'Average of both')}</div>
                    <div class="merge-label">Unit</div>
                    <div class="merge-info">${escHtml(unit(s))}</div>
                    <div class="merge-info">${escHtml(unit(t))}</div>
                    <div class="merge-label">Used</div>
                    <div class="merge-info">${escHtml(use(s))}</div>
                    <div class="merge-info">${escHtml(use(t))}</div>
                </div>
                ${unitNote}
            </div>`;
        }).join('');
    }

    // Assigned, not added: a container reused for another pair must drop the previous handler
    container.onchange = e => {
        const m = state[e.target.dataset.i];
        if (!m) return;
        if (e.target.type === 'checkbox') m.active = e.target.checked;
        // Choosing a name or macros means the pair is a match
        else { m[e.target.dataset.field] = e.target.value; m.active = true; }
        render();
    };
    render();

    async function post(fields) {
        const data = new FormData();
        Object.entries(fields).forEach(([k, v]) => data.append(k, v));
        return fetch('../core/api.php', { method: 'POST', body: data }).then(r => r.json()).catch(() => ({ status: 'error', message: 'Request failed' }));
    }

    async function apply({ dismissUnticked = false } = {}) {
        const failures = [];
        const merged = new Set();
        for (const m of state) {
            if (m.active) {
                // A product only merges once; a second tick for it would point at a merged product
                if (merged.has(m.source_id)) continue;
                merged.add(m.source_id);
                const res = await post({ action: 'merge', source_id: m.source_id, target_id: m.target_id, name_from: m.name, macros: m.macros });
                if (res.status !== 'success') failures.push(`${m.source.name}: ${res.message}`);
            } else if (dismissUnticked) {
                await post({ action: 'dismiss_dedupe_pair', id_a: m.source_id, id_b: m.target_id });
            }
        }
        return failures;
    }

    return { apply };
}

/**
 * Carry a picked file across a page navigation (dashboard quick actions). The file picker
 * needs a click on the page that opens it, so the dashboard picks the photo and the target
 * page collects it. IndexedDB holds the Blob; sessionStorage can't fit a receipt photo.
 */
function spenceFileStore(mode, fn) {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open('spence-handoff', 1);
        open.onupgradeneeded = () => open.result.createObjectStore('files');
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
            const tx = open.result.transaction('files', mode);
            const req = fn(tx.objectStore('files'));
            tx.oncomplete = () => resolve(req.result);
            tx.onerror = () => reject(tx.error);
        };
    });
}

function handoffFile(file, url) {
    spenceFileStore('readwrite', store => store.put(file, 'pending'))
        .then(() => { location.href = url; })
        .catch(() => alert('Could not hand the photo over — open the page and pick it there.'));
}

// Resolves to the handed-off File (or null) and clears it so a reload doesn't rescan.
function takeHandoffFile() {
    return spenceFileStore('readwrite', store => store.get('pending'))
        .then(file => spenceFileStore('readwrite', store => store.delete('pending')).then(() => file || null))
        .catch(() => null);
}
