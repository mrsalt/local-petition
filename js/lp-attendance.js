(function () {
    const candidates = (window.lpAttendance && window.lpAttendance.candidates) || [];
    const input = document.getElementById('lp-attendance-name');
    const candidateList = document.getElementById('lp-attendance-candidates');
    const attendeeList = document.getElementById('lp-attendance-list');
    const countEl = document.getElementById('lp-attendance-count');
    if (!input) return;

    const attendees = []; // candidate objects, in order added
    const MAX_SHOWN = 10;

    const label = c => c.city ? `${c.name} (${c.city})` : c.name;

    function matches() {
        const q = input.value.trim().toLowerCase();
        if (!q) return [];
        const present = new Set(attendees.map(a => a.id));
        return candidates.filter(c => !present.has(c.id) && c.name.toLowerCase().includes(q));
    }

    function renderCandidates() {
        const found = matches();
        candidateList.replaceChildren();
        found.slice(0, MAX_SHOWN).forEach(c => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button-link';
            btn.textContent = label(c);
            btn.addEventListener('click', () => add(c));
            li.appendChild(btn);
            candidateList.appendChild(li);
        });
        if (found.length > MAX_SHOWN) {
            const li = document.createElement('li');
            li.textContent = `…and ${found.length - MAX_SHOWN} more`;
            candidateList.appendChild(li);
        }
    }

    function renderAttendees() {
        attendeeList.replaceChildren();
        attendees.forEach(a => {
            const li = document.createElement('li');
            li.append(label(a) + ' ');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button-link';
            btn.textContent = 'remove';
            btn.addEventListener('click', () => {
                attendees.splice(attendees.indexOf(a), 1);
                renderAttendees();
                renderCandidates();
            });
            li.appendChild(btn);
            attendeeList.appendChild(li);
        });
        countEl.textContent = attendees.length;
    }

    function add(c) {
        attendees.push(c);
        input.value = '';
        renderAttendees();
        renderCandidates();
        input.focus();
    }

    input.addEventListener('input', renderCandidates);
    input.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const found = matches();
        if (found.length === 1) add(found[0]);
    });
})();
