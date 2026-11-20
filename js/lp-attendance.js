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
    let attendees = []; // {kind, id, name, city, address, email, phone, saved, checked}, sorted by name
    // kind is 'signer' (CFAL member) or 'person' (non-CFAL member); ids are only unique within a kind.
    let shown = [];     // candidates currently listed
    let active = -1;    // index into shown highlighted by arrow keys
    let loadToken = 0;  // discards out-of-date attendee loads

    const key = c => `${c.kind}:${c.id}`;
    const displayName = c => c.kind === 'person' ? c.name + '*' : c.name;
    const label = c => c.city ? `${displayName(c)} (${c.city})` : displayName(c);
    const idsOfKind = (list, kind) => list.filter(a => a.kind === kind).map(a => a.id);
    const currentEventId = () => parseInt(eventSelect.value, 10) || 0;
    const hasUnsaved = () => attendees.some(a => !a.saved);

    function setStatus(text, isError) {
        statusEl.textContent = text;
        statusEl.style.color = isError ? '#d63638' : '#00a32a';
    }

    function post(action, data) {
        const body = new URLSearchParams({ action, nonce: cfg.nonce, event_id: currentEventId(), ...(data.fields || {}) });
        (data.signer_ids || []).forEach(id => body.append('signer_ids[]', id));
        (data.person_ids || []).forEach(id => body.append('person_ids[]', id));
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

    const queryText = () => input.value.trim().toLowerCase();
    const nameMatches = c => c.name.toLowerCase().includes(queryText());

    function matches() {
        if (!queryText()) return [];
        const present = new Set(attendees.map(key));
        return candidates.filter(c => !present.has(key(c)) && nameMatches(c));
    }

    function renderCandidates() {
        const found = matches();
        // Nobody at all (attending or not) matches: offer to add a non-CFAL member.
        const offerNew = queryText() && !candidates.some(nameMatches) && !attendees.some(nameMatches);
        shown = offerNew ? [{ kind: 'new' }] : found.slice(0, MAX_SHOWN);
        if (active >= shown.length) active = shown.length - 1;
        candidateList.replaceChildren();
        shown.forEach((c, i) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            if (c.kind === 'new') {
                li.className = 'add-person';
                li.textContent = `No match. Add “${input.value.trim()}” as a non-CFAL member…`;
            } else {
                li.textContent = label(c);
            }
            if (i === active) li.classList.add('active');
            // mousedown (not click) so the input keeps focus
            li.addEventListener('mousedown', e => { e.preventDefault(); add(c); });
            candidateList.appendChild(li);
        });
        if (!offerNew && found.length > MAX_SHOWN) {
            const li = document.createElement('li');
            li.className = 'more';
            li.textContent = `…and ${found.length - MAX_SHOWN} more`;
            candidateList.appendChild(li);
        }
        const open = shown.length > 0;
        combo.classList.toggle('open', open);
        input.setAttribute('aria-expanded', open);
    }

    function add(c) {
        if (!currentEventId()) { setStatus('Select a meeting first.', true); return; }
        if (c.kind === 'new') { openPersonDialog(input.value.trim()); return; }
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
            box.setAttribute('aria-label', 'Select ' + displayName(a));
            box.addEventListener('change', () => { a.checked = box.checked; updateButtons(); });
            checkCell.appendChild(box);
            const nameCell = document.createElement('td');
            nameCell.textContent = displayName(a);
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
        if (!attendees.length) { setStatus('Nobody to save.', true); return; }
        const count = attendees.length;
        const savedKeys = new Set(attendees.map(key));
        const token = loadToken;
        saveBtn.disabled = true;
        setStatus('Saving…');
        post('lp_attendance_save', { signer_ids: idsOfKind(attendees, 'signer'), person_ids: idsOfKind(attendees, 'person') }).then(() => {
            if (token !== loadToken) return;
            attendees.forEach(a => { if (savedKeys.has(key(a))) a.saved = true; });
            renderAttendees();
            setStatus(`Saved ${count} attendee${count === 1 ? '' : 's'}.`);
        }).catch(err => { setStatus('Save failed: ' + err.message, true); updateButtons(); });
    });

    removeBtn.addEventListener('click', () => {
        const checked = attendees.filter(a => a.checked);
        if (!checked.length) return;
        const saved = checked.filter(a => a.saved);
        const token = loadToken;
        removeBtn.disabled = true;
        const done = saved.length
            ? post('lp_attendance_remove', { signer_ids: idsOfKind(saved, 'signer'), person_ids: idsOfKind(saved, 'person') })
            : Promise.resolve();
        done.then(() => {
            if (token !== loadToken) return;
            attendees = attendees.filter(a => !a.checked);
            renderAttendees();
            renderCandidates();
            setStatus(`Removed ${checked.length}.`);
        }).catch(err => { setStatus('Remove failed: ' + err.message, true); updateButtons(); });
    });

    // ---- Add non-CFAL member ----------------------------------------------------

    const personDialog = $('lp-person-dialog');
    const personForm = $('lp-person-form');
    const personError = $('lp-person-error');
    const personAddBtn = $('lp-person-add');

    function openPersonDialog(name) {
        personForm.reset();
        personForm.elements.name.value = name;
        personError.textContent = '';
        personDialog.showModal();
        (name ? personForm.elements.email : personForm.elements.name).focus();
    }

    $('lp-person-cancel').addEventListener('click', () => { personDialog.close(); input.focus(); });
    personDialog.addEventListener('close', () => input.focus());

    personForm.addEventListener('submit', e => {
        e.preventDefault(); // stay open until the server accepts the person
        const fields = Object.fromEntries(new FormData(personForm).entries());
        personAddBtn.disabled = true;
        personError.textContent = 'Saving…';
        personError.style.color = '';
        post('lp_attendance_add_person', { fields }).then(person => {
            candidates.push(person);
            candidates.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
            personDialog.close();
            add(person);
        }).catch(err => {
            personError.style.color = '#d63638';
            personError.textContent = err.message;
        }).finally(() => { personAddBtn.disabled = false; });
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
