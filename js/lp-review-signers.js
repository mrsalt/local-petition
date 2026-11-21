(function () {
    const cfg = window.lpSigners;
    const root = document.getElementById('lp-signers-app');
    if (!cfg || !root) return;

    const rows = cfg.rows || [];
    const DEFAULT_LIMIT = 25;
    const PAGE_SIZES = [25, 50, 100, 250, 500, 1000];
    const INTERNAL = new Set(['slug', 'CampaignStatus']); // never shown as columns
    const NO_FILTER = new Set(['ID', 'Created', 'Name', 'Photo', 'Title', 'Email', 'Phone', 'Comments', 'Line 1', 'Line 2']);
    const YES_NO = new Set(['Share Public', 'Helper', 'Supporter']);
    const COOKIE = 'lp-hidden-columns-signers';
    const CookieJar = window.Cookies;
    const columns = rows.length ? Object.keys(rows[0]).filter(k => !INTERNAL.has(k)) : [];
    const filterColumns = columns.filter(c => !NO_FILTER.has(c));

    // ---- Helpers ----------------------------------------------------------------

    function el(tag, props, ...kids) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(props || {})) {
            if (k === 'class') e.className = v;
            else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
            else if (v === true) e.setAttribute(k, '');
            else if (v !== false && v != null) e.setAttribute(k, v);
        }
        kids.flat().forEach(c => { if (c != null && c !== false) e.append(c); });
        return e;
    }

    function display(key, value) {
        if (value === null || value === undefined) return '';
        if (YES_NO.has(key)) {
            if (value === '0' || value === 0) return 'No';
            if (value === '1' || value === 1) return 'Yes';
        }
        return String(value);
    }

    function photoUrl(row) {
        return row.Photo
            ? `/wp-content/uploads/local-petition/${row.slug}/${row.ID}/${row.Photo}`
            : '/wp-content/plugins/local-petition/images/placeholder-image-person.png';
    }

    function loadHidden() {
        try {
            const raw = CookieJar && CookieJar.get(COOKIE);
            const list = raw ? JSON.parse(raw) : [];
            return Array.isArray(list) ? list.filter(c => columns.includes(c)) : [];
        } catch (e) {
            return [];
        }
    }

    function saveHidden(list) {
        if (CookieJar) CookieJar.set(COOKIE, JSON.stringify(list), { expires: 365, path: '/' });
    }

    function makeDialog(title, className) {
        const dlg = el('dialog', { class: 'lp-dialog ' + (className || '') }, el('h2', null, title));
        document.body.appendChild(dlg);
        return dlg;
    }

    // ---- URL state --------------------------------------------------------------

    let state = readState();
    let hidden = loadHidden();

    function readState() {
        const params = new URLSearchParams(location.search);
        const names = new Set([...columns, 'CampaignStatus']);
        const filters = {};
        params.forEach((value, key) => {
            // WordPress turns spaces in parameter names into underscores; accept both.
            const name = names.has(key) ? key : key.replace(/_/g, ' ');
            if (names.has(name)) filters[name] = value;
        });
        if (!('CampaignStatus' in filters)) filters.CampaignStatus = 'Active';
        let limit = parseInt(params.get('limit'), 10);
        if (!(limit > 0)) limit = DEFAULT_LIMIT;
        let offset = parseInt(params.get('offset'), 10);
        if (!(offset > 0)) offset = 0;
        return { filters, limit, offset };
    }

    function urlFor(st) {
        const params = new URLSearchParams();
        params.set('page', cfg.page);
        for (const [key, value] of Object.entries(st.filters)) {
            if (key === 'CampaignStatus' && value === 'Active') continue;
            params.set(key, value);
        }
        if (st.limit !== DEFAULT_LIMIT) params.set('limit', st.limit);
        if (st.offset > 0) params.set('offset', st.offset);
        return '?' + params.toString();
    }

    function navigate(st) {
        history.pushState(null, '', urlFor(st));
        state = st;
        render();
    }

    window.addEventListener('popstate', () => { state = readState(); render(); });

    function filteredRows() {
        const entries = Object.entries(state.filters);
        return rows.filter(r => entries.every(([key, value]) =>
            key === 'CampaignStatus' ? r.CampaignStatus === value : display(key, r[key]) === value));
    }

    // ---- Main table -------------------------------------------------------------

    function filterBar() {
        const histogram = {};
        rows.forEach(r => filterColumns.forEach(c => {
            const v = display(c, r[c]);
            const m = histogram[c] || (histogram[c] = new Map());
            m.set(v, (m.get(v) || 0) + 1);
        }));
        const labelRow = el('tr', null, filterColumns.map(c => el('td', null, c)), el('td'));
        const selectRow = el('tr', null, filterColumns.map(c => {
            const select = el('select', { onchange: () => {
                const filters = { ...state.filters };
                if (select.value === '<All>') delete filters[c]; else filters[c] = select.value;
                navigate({ ...state, filters, offset: 0 });
            } }, el('option', { value: '<All>' }, '<All>'));
            for (const [value, count] of histogram[c] || []) {
                const o = el('option', { value }, `${value} (${count})`);
                o.selected = state.filters[c] === value;
                select.appendChild(o);
            }
            return el('td', null, select);
        }), el('td', null, el('button', { type: 'button', class: 'button', onclick: openColumnsDialog }, 'Visible Columns')));
        return el('table', { class: 'lp-filters' }, labelRow, selectRow);
    }

    function dataTable(pageRows) {
        const visible = columns.filter(c => !hidden.includes(c));
        const all = el('input', { type: 'checkbox' });
        all.addEventListener('click', () => {
            table.querySelectorAll('td.lp-table-data input[type=checkbox]').forEach(cb => { cb.checked = all.checked; });
        });
        const table = el('table', { class: 'lp-table' },
            el('tr', { class: 'lp-table-header-row' }, el('th', null, all),
                visible.map(c => el('th', { class: 'lp-table-header' }, c))));
        pageRows.forEach((r, i) => {
            table.appendChild(el('tr', { class: 'lp-table-row ' + (i % 2 === 0 ? 'lp-even-row' : 'lp-odd-row') },
                el('td', { class: 'lp-table-data' }, el('input', { type: 'checkbox', name: `user-id[${r.ID}]` })),
                visible.map(c => el('td', { class: 'lp-table-data' },
                    c === 'Photo' ? el('img', { src: photoUrl(r), style: 'width: 100px;' }) : display(c, r[c])))));
        });
        return table;
    }

    function bulkControls() {
        const option = v => el('option', null, v);
        const div = el('div');
        if ('Status' in state.filters) {
            div.append('Change status of selected to: ',
                el('select', { name: 'new_status' }, ['-- No Change --', 'Unreviewed', 'Approved', 'Quarantined', 'Duplicate'].map(option)));
        }
        div.append(' change email to: ',
            el('select', { name: 'new_email_status' }, ['-- No Change --', 'Unknown', 'Valid', 'Full', 'Invalid', 'Unsubscribed'].map(option)),
            ' ', el('input', { type: 'submit' }));
        return div;
    }

    function pager(total) {
        const div = el('div', { class: 'lp-pager' }, 'Page ');
        for (let page = 0; page * state.limit < total; page++) {
            const offset = page * state.limit;
            if (offset === state.offset) {
                div.appendChild(el('b', null, page + 1));
            } else {
                div.appendChild(el('a', { href: urlFor({ ...state, offset }), onclick: e => {
                    e.preventDefault();
                    navigate({ ...state, offset });
                } }, page + 1));
            }
        }
        return div;
    }

    function perPage() {
        const select = el('select', { onchange: () => {
            const limit = parseInt(select.value, 10);
            navigate({ ...state, limit, offset: Math.floor(state.offset / limit) * limit });
        } }, PAGE_SIZES.map(n => {
            const o = el('option', { value: n }, n);
            o.selected = n === state.limit;
            return o;
        }));
        return el('div', null, 'Results per page: ', select);
    }

    function render() {
        const matching = filteredRows();
        if (state.offset >= matching.length && matching.length > 0) {
            state = { ...state, offset: Math.floor((matching.length - 1) / state.limit) * state.limit };
        }
        const pageRows = matching.slice(state.offset, state.offset + state.limit);
        root.replaceChildren(filterBar());
        if (!matching.length) {
            root.append(el('br'), el('p', null, 'There are no users that match the current criteria.'));
        } else {
            root.append(el('form', { method: 'post' }, dataTable(pageRows), el('br'), bulkControls()),
                el('div', null, `${matching.length} results`), pager(matching.length), perPage(),
                el('a', { href: '/wp-admin/admin-ajax.php?action=lp_fetch_signers', download: `signers-${new Date().toISOString().slice(0, 10)}.csv` }, 'Download'));
        }
        root.append(el('div', { class: 'lp-bottom-buttons' },
            el('button', { type: 'button', class: 'button', onclick: openSearchDialog }, 'Search'), ' ',
            el('button', { type: 'button', class: 'button', onclick: openEmailDialog }, 'Email')));
    }

    // ---- Visible Columns dialog -------------------------------------------------

    let columnsDialog, availableSelect, visibleSelect, workingHidden;

    function refillColumnLists() {
        const fill = (select, names) => {
            select.replaceChildren(...names.map(n => el('option', { value: n }, n)));
        };
        fill(availableSelect, columns.filter(c => workingHidden.includes(c)));
        fill(visibleSelect, columns.filter(c => !workingHidden.includes(c)));
    }

    function moveColumns(from, hide) {
        const picked = [...from.selectedOptions].map(o => o.value);
        workingHidden = hide ? [...new Set([...workingHidden, ...picked])] : workingHidden.filter(c => !picked.includes(c));
        refillColumnLists();
    }

    function openColumnsDialog() {
        if (!columnsDialog) {
            columnsDialog = makeDialog('Visible Columns');
            availableSelect = el('select', { multiple: true, size: 18, id: 'lp-columns-available' });
            visibleSelect = el('select', { multiple: true, size: 18, id: 'lp-columns-visible' });
            columnsDialog.append(
                el('div', { class: 'lp-columns-lists' },
                    el('div', null, el('label', { for: 'lp-columns-available' }, el('strong', null, 'Available')), el('br'), availableSelect),
                    el('div', { class: 'lp-columns-buttons' },
                        el('button', { type: 'button', class: 'button', title: 'Make visible', onclick: () => moveColumns(availableSelect, false) }, 'Add →'),
                        el('button', { type: 'button', class: 'button', title: 'Hide', onclick: () => moveColumns(visibleSelect, true) }, '← Remove')),
                    el('div', null, el('label', { for: 'lp-columns-visible' }, el('strong', null, 'Visible')), el('br'), visibleSelect)),
                el('p', null,
                    el('button', { type: 'button', class: 'button button-primary', onclick: () => {
                        hidden = workingHidden.slice();
                        saveHidden(hidden);
                        columnsDialog.close();
                        render();
                    } }, 'OK'), ' ',
                    el('button', { type: 'button', class: 'button', onclick: () => columnsDialog.close() }, 'Cancel')));
        }
        workingHidden = hidden.slice();
        refillColumnLists();
        columnsDialog.showModal();
    }

    // ---- Search dialog ----------------------------------------------------------

    let searchDialog, searchInput, searchCombo, searchList, searchDetails, searchShown = [], searchActive = -1;
    const MAX_SHOWN = 10;

    function searchMatches() {
        const q = searchInput.value.trim().toLowerCase();
        return q ? rows.filter(r => (r.Name || '').toLowerCase().includes(q)) : [];
    }

    function renderSearchList() {
        const found = searchMatches();
        searchShown = found.slice(0, MAX_SHOWN);
        if (searchActive >= searchShown.length) searchActive = searchShown.length - 1;
        searchList.replaceChildren(...searchShown.map((r, i) => {
            const li = el('li', { role: 'option', class: i === searchActive ? 'active' : '' },
                r.City ? `${r.Name} (${r.City})` : r.Name);
            li.addEventListener('mousedown', e => { e.preventDefault(); pickPerson(r); });
            return li;
        }));
        if (found.length > MAX_SHOWN) searchList.appendChild(el('li', { class: 'more' }, `…and ${found.length - MAX_SHOWN} more`));
        searchCombo.classList.toggle('open', found.length > 0);
    }

    function pickPerson(row) {
        searchInput.value = '';
        searchActive = -1;
        renderSearchList();
        const table = el('table', { class: 'lp-details' });
        [...columns, 'CampaignStatus'].forEach(c => {
            const label = c === 'CampaignStatus' ? 'Campaign Status' : c;
            table.appendChild(el('tr', null, el('th', null, label + ':'),
                el('td', null, c === 'Photo' ? el('img', { src: photoUrl(row), style: 'width: 100px;' }) : display(c, row[c]))));
        });
        searchDetails.replaceChildren(table);
        searchInput.focus();
    }

    function openSearchDialog() {
        if (!searchDialog) {
            searchDialog = makeDialog('Search signers');
            searchInput = el('input', { type: 'text', autocomplete: 'off', role: 'combobox', placeholder: 'Start typing a name' });
            searchList = el('ul', { role: 'listbox' });
            searchCombo = el('div', { class: 'lp-combo' }, searchInput, searchList);
            searchDetails = el('div');
            searchInput.addEventListener('input', () => { searchActive = -1; renderSearchList(); });
            searchInput.addEventListener('focus', renderSearchList);
            searchInput.addEventListener('blur', () => searchCombo.classList.remove('open'));
            searchInput.addEventListener('keydown', e => {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    if (!searchShown.length) return;
                    e.preventDefault();
                    searchActive = (searchActive + (e.key === 'ArrowDown' ? 1 : -1) + searchShown.length) % searchShown.length;
                    renderSearchList();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (searchActive >= 0 && searchShown[searchActive]) pickPerson(searchShown[searchActive]);
                    else if (searchMatches().length === 1) pickPerson(searchMatches()[0]);
                } else if (e.key === 'Escape' && searchCombo.classList.contains('open')) {
                    e.stopPropagation(); // close the list first, the dialog on the next Escape
                    e.preventDefault();
                    searchCombo.classList.remove('open');
                }
            });
            searchDialog.append(searchCombo, searchDetails,
                el('p', null, el('button', { type: 'button', class: 'button', onclick: () => searchDialog.close() }, 'Close')));
        }
        searchDialog.showModal();
        searchInput.focus();
    }

    // ---- Email dialog -----------------------------------------------------------

    const EMAIL_STATUSES = ['Unknown', 'Valid', 'Full', 'Invalid', 'Unsubscribed'];
    const EMAIL_DEFAULT_ON = new Set(['Unknown', 'Valid', 'Full']);
    const EXCLUDED_REVIEW_STATUSES = new Set(['Quarantined', 'Duplicate']);
    let emailDialog, statusBoxes, cityBoxes, cityPicker, cityButton, quantityInput, emailResult;

    const emailEligible = () => rows.filter(r => r.CampaignStatus === 'Active' && !EXCLUDED_REVIEW_STATUSES.has(r.Status));

    function updateCityButton() {
        const on = cityBoxes.filter(b => b.checked).length;
        cityButton.textContent = `City (${on} of ${cityBoxes.length} selected) ▾`;
    }

    function buildEmailDialog() {
        emailDialog = makeDialog('Email addresses');
        statusBoxes = EMAIL_STATUSES.map(s => {
            const box = el('input', { type: 'checkbox', value: s });
            box.checked = EMAIL_DEFAULT_ON.has(s);
            if (s === 'Unsubscribed') { box.checked = false; box.disabled = true; }
            return box;
        });
        const statusFieldset = el('fieldset', null, el('legend', null, 'Email status'),
            statusBoxes.map(b => el('label', null, b, ' ' + b.value)));

        const cities = [...new Set(emailEligible().map(r => r.City).filter(Boolean))].sort();
        const defaults = new Set((cfg.defaultCities || []).map(c => c.toUpperCase()));
        cityBoxes = cities.map(c => {
            const box = el('input', { type: 'checkbox', value: c });
            box.checked = defaults.size === 0 || defaults.has(c.toUpperCase());
            box.addEventListener('change', updateCityButton);
            return box;
        });
        cityButton = el('button', { type: 'button', class: 'button', onclick: () => cityPicker.classList.toggle('open') });
        cityPicker = el('div', { class: 'lp-city-picker' }, cityButton,
            el('div', { class: 'lp-city-panel' },
                el('div', null,
                    el('a', { href: '#', onclick: e => { e.preventDefault(); cityBoxes.forEach(b => { b.checked = true; }); updateCityButton(); } }, 'All'), ' | ',
                    el('a', { href: '#', onclick: e => { e.preventDefault(); cityBoxes.forEach(b => { b.checked = false; }); updateCityButton(); } }, 'None')),
                cityBoxes.map(b => el('label', null, b, ' ' + b.value))));
        updateCityButton();

        quantityInput = el('input', { type: 'number', min: 1, value: 300, style: 'width: 6em' });
        emailResult = el('div', { class: 'lp-email-result' });

        emailDialog.append(
            el('div', { class: 'lp-email-options' },
                statusFieldset,
                el('p', null, el('strong', null, 'City '), cityPicker),
                el('p', null, el('label', null, el('strong', null, 'Quantity per message '), quantityInput))),
            el('p', null,
                el('button', { type: 'button', class: 'button button-primary', onclick: generateEmailLists }, 'OK'), ' ',
                el('button', { type: 'button', class: 'button', onclick: () => emailDialog.close() }, 'Close')),
            emailResult);
        emailDialog.addEventListener('click', e => {
            if (!cityPicker.contains(e.target)) cityPicker.classList.remove('open');
        });
    }

    function copyText(textarea, button) {
        const done = () => { button.textContent = 'Copied'; setTimeout(() => { button.textContent = 'Copy'; }, 1500); };
        textarea.select();
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textarea.value).then(done, () => { document.execCommand('copy'); done(); });
        } else {
            document.execCommand('copy');
            done();
        }
    }

    function generateEmailLists() {
        const quantity = parseInt(quantityInput.value, 10);
        if (!(quantity >= 1)) {
            emailResult.replaceChildren(el('p', { style: 'color:#d63638' }, 'Quantity per message must be a whole number of at least 1.'));
            return;
        }
        const statuses = new Set(statusBoxes.filter(b => b.checked).map(b => b.value));
        const cities = new Set(cityBoxes.filter(b => b.checked).map(b => b.value));
        const seen = new Set();
        const emails = [];
        let signers = 0;
        emailEligible().forEach(r => {
            const email = (r.Email || '').trim();
            if (!email || !statuses.has(r['Email Status']) || !cities.has(r.City)) return;
            signers++;
            const k = email.toLowerCase();
            if (seen.has(k)) return;
            seen.add(k);
            emails.push(email);
        });
        if (!emails.length) {
            emailResult.replaceChildren(el('p', null, 'No email addresses match the selected criteria.'));
            return;
        }
        const chunks = [];
        for (let i = 0; i < emails.length; i += quantity) chunks.push(emails.slice(i, i + quantity));
        emailResult.replaceChildren(
            el('p', null, `${emails.length} unique email address${emails.length === 1 ? '' : 'es'} (from ${signers} signer${signers === 1 ? '' : 's'}) in ${chunks.length} message${chunks.length === 1 ? '' : 's'}.`),
            ...chunks.map((chunk, i) => {
                const box = el('textarea', { rows: 5, readonly: true }, chunk.join(', '));
                const copy = el('button', { type: 'button', class: 'button', onclick: () => copyText(box, copy) }, 'Copy');
                return el('div', null,
                    el('p', null, el('strong', null, `Message ${i + 1} of ${chunks.length}`), ` (${chunk.length} addresses) `, copy), box);
            }));
    }

    function openEmailDialog() {
        if (!emailDialog) buildEmailDialog();
        emailDialog.showModal();
    }

    render();
})();
