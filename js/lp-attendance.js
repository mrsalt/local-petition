(function () {
    const cfg = window.lpAttendance || {};
    const candidates = cfg.candidates || [];
    const events = cfg.events || [];
    const $ = id => document.getElementById(id);
    const input = $('lp-attendance-name');
    const combo = $('lp-attendance-combo');
    const candidateList = $('lp-attendance-candidates');
    const attendeeList = $('lp-attendance-list').tBodies[0];
    const countEl = $('lp-attendance-count');
    const yearSelect = $('lp-attendance-year');
    const eventSelect = $('lp-attendance-event');
    const saveBtn = $('lp-attendance-save');
    const removeBtn = $('lp-attendance-remove');
    const statusEl = $('lp-attendance-status');
    const scrollBox = $('lp-attendance-scroll');
    const actions = $('lp-attendance-actions');
    if (!input) return;

    const MAX_SHOWN = 10;
    let attendees = []; // {id, name, city, address, email, phone, saved, checked}, sorted by name
    let shown = [];     // candidates currently listed
    let active = -1;    // index into shown highlighted by arrow keys
    let loadToken = 0;  // discards out-of-date attendee loads

    const label = c => c.city ? `${c.name} (${c.city})` : c.name;
    const currentEventId = () => parseInt(eventSelect.value, 10) || 0;
    const hasUnsaved = () => attendees.some(a => !a.saved);

    function setStatus(text, isError) {
        statusEl.textContent = text;
        statusEl.style.color = isError ? '#d63638' : '#00a32a';
    }

    function post(action, data) {
        const body = new URLSearchParams({ action, nonce: cfg.nonce, event_id: currentEventId() });
        (data.signer_ids || []).forEach(id => body.append('signer_ids[]', id));
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
            .then(r => r.json())
            .then(j => {
                if (!j.success) throw new Error(typeof j.data === 'string' ? j.data : 'Request failed');
                return j.data;
            });
    }

    // ---- Year / event selection -------------------------------------------------

    function populateYears() {
        const years = new Set(events.map(e => e.year));
        years.add(cfg.currentYear);
        [...years].sort((a, b) => b - a).forEach(y => {
            const o = document.createElement('option');
            o.value = o.textContent = y;
            yearSelect.appendChild(o);
        });
        yearSelect.value = cfg.currentYear;
    }

    function populateEvents() {
        const year = parseInt(yearSelect.value, 10);
        const list = events.filter(e => e.year === year); // already most recent first
        eventSelect.replaceChildren();
        if (!list.length) {
            const o = document.createElement('option');
            o.textContent = 'No meetings this year';
            o.value = '';
            eventSelect.appendChild(o);
            eventSelect.disabled = true;
            return;
        }
        eventSelect.disabled = false;
        list.forEach(e => {
            const o = document.createElement('option');
            o.value = e.id;
            o.textContent = `${e.label} — ${e.title}`;
            eventSelect.appendChild(o);
        });
        // Default to the most recent meeting that is not in the future.
        const past = list.find(e => e.date <= cfg.today);
        eventSelect.value = (past || list[0]).id;
    }

    function loadAttendees() {
        const token = ++loadToken;
        attendees = [];
        renderAttendees();
        setStatus('');
        if (!currentEventId()) return;
        post('lp_attendance_get', {}).then(rows => {
            if (token !== loadToken) return;
            attendees = rows.map(r => ({ ...r, saved: true, checked: false }));
            renderAttendees();
            renderCandidates();
        }).catch(err => token === loadToken && setStatus('Could not load attendees: ' + err.message, true));
    }

    function confirmDiscard() {
        return !hasUnsaved() || confirm('You have attendees that have not been saved. Discard them?');
    }

    let lastYear, lastEvent;
    yearSelect.addEventListener('change', () => {
        if (!confirmDiscard()) { yearSelect.value = lastYear; return; }
        populateEvents();
        lastYear = yearSelect.value; lastEvent = eventSelect.value;
        loadAttendees();
    });
    eventSelect.addEventListener('change', () => {
        if (!confirmDiscard()) { eventSelect.value = lastEvent; return; }
        lastEvent = eventSelect.value;
        loadAttendees();
    });

    // ---- Autocomplete -----------------------------------------------------------

    function matches() {
        const q = input.value.trim().toLowerCase();
        if (!q) return [];
        const present = new Set(attendees.map(a => a.id));
        return candidates.filter(c => !present.has(c.id) && c.name.toLowerCase().includes(q));
    }

    function renderCandidates() {
        const found = matches();
        shown = found.slice(0, MAX_SHOWN);
        if (active >= shown.length) active = shown.length - 1;
        candidateList.replaceChildren();
        shown.forEach((c, i) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.textContent = label(c);
            if (i === active) li.className = 'active';
            // mousedown (not click) so the input keeps focus
            li.addEventListener('mousedown', e => { e.preventDefault(); add(c); });
            candidateList.appendChild(li);
        });
        if (found.length > MAX_SHOWN) {
            const li = document.createElement('li');
            li.className = 'more';
            li.textContent = `…and ${found.length - MAX_SHOWN} more`;
            candidateList.appendChild(li);
        }
        const open = found.length > 0;
        combo.classList.toggle('open', open);
        input.setAttribute('aria-expanded', open);
    }

    function add(c) {
        if (!currentEventId()) { setStatus('Select a meeting first.', true); return; }
        attendees.push({ ...c, saved: false, checked: false });
        input.value = '';
        active = -1;
        setStatus('');
        renderAttendees();
        renderCandidates();
        input.focus();
    }

    input.addEventListener('input', () => { active = -1; renderCandidates(); });
    input.addEventListener('blur', () => { combo.classList.remove('open'); input.setAttribute('aria-expanded', false); });
    input.addEventListener('focus', renderCandidates);
    input.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!shown.length) return;
            e.preventDefault();
            const step = e.key === 'ArrowDown' ? 1 : -1;
            active = (active + step + shown.length) % shown.length;
            renderCandidates();
        } else if (e.key === 'Escape') {
            active = -1;
            combo.classList.remove('open');
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (active >= 0 && shown[active]) add(shown[active]);
            else if (matches().length === 1) add(matches()[0]);
        }
    });

    // ---- Attendee list ----------------------------------------------------------

    function updateButtons() {
        removeBtn.disabled = !attendees.some(a => a.checked);
        saveBtn.disabled = !currentEventId();
    }

    const byName = (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });

    function renderAttendees() {
        attendees.sort(byName);
        attendeeList.replaceChildren();
        attendees.forEach(a => {
            const tr = document.createElement('tr');
            const checkCell = document.createElement('td');
            checkCell.className = 'check-column';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = a.checked;
            box.setAttribute('aria-label', 'Select ' + a.name);
            box.addEventListener('change', () => { a.checked = box.checked; updateButtons(); });
            checkCell.appendChild(box);
            const nameCell = document.createElement('td');
            nameCell.textContent = a.name;
            if (!a.saved) {
                const tag = document.createElement('span');
                tag.className = 'unsaved';
                tag.textContent = ' (not saved)';
                nameCell.appendChild(tag);
            }
            tr.appendChild(checkCell);
            tr.appendChild(nameCell);
            [a.address, a.email, a.phone].forEach(text => {
                const td = document.createElement('td');
                td.textContent = text || '';
                tr.appendChild(td);
            });
            attendeeList.appendChild(tr);
        });
        countEl.textContent = attendees.length;
        updateButtons();
        fitList();
    }

    // Keep the list at least 200px tall, but no taller than leaves the buttons
    // below it on screen (measured from where the list sits on the page).
    function fitList() {
        const top = scrollBox.getBoundingClientRect().top + window.scrollY;
        const below = actions.offsetHeight + 60; // buttons + footer breathing room
        scrollBox.style.maxHeight = Math.max(200, window.innerHeight - top - below) + 'px';
    }
    window.addEventListener('resize', fitList);

    saveBtn.addEventListener('click', () => {
        const ids = attendees.map(a => a.id);
        if (!ids.length) { setStatus('Nobody to save.', true); return; }
        const token = loadToken;
        saveBtn.disabled = true;
        setStatus('Saving…');
        post('lp_attendance_save', { signer_ids: ids }).then(() => {
            if (token !== loadToken) return;
            attendees.forEach(a => { if (ids.includes(a.id)) a.saved = true; });
            renderAttendees();
            setStatus(`Saved ${ids.length} attendee${ids.length === 1 ? '' : 's'}.`);
        }).catch(err => { setStatus('Save failed: ' + err.message, true); updateButtons(); });
    });

    removeBtn.addEventListener('click', () => {
        const checked = attendees.filter(a => a.checked);
        if (!checked.length) return;
        const savedIds = checked.filter(a => a.saved).map(a => a.id);
        const token = loadToken;
        removeBtn.disabled = true;
        const done = savedIds.length ? post('lp_attendance_remove', { signer_ids: savedIds }) : Promise.resolve();
        done.then(() => {
            if (token !== loadToken) return;
            attendees = attendees.filter(a => !a.checked);
            renderAttendees();
            renderCandidates();
            setStatus(`Removed ${checked.length}.`);
        }).catch(err => { setStatus('Remove failed: ' + err.message, true); updateButtons(); });
    });

    window.addEventListener('beforeunload', e => {
        if (hasUnsaved()) { e.preventDefault(); e.returnValue = ''; }
    });

    populateYears();
    populateEvents();
    lastYear = yearSelect.value;
    lastEvent = eventSelect.value;
    loadAttendees();
})();
