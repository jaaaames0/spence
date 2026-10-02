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
