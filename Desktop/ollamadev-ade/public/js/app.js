class App {
    constructor() {
        this.currentTab = 'terminals';
        this.paneCount = 1;
        this.terminals = new Map();
        this.tasks = [];
        this.notes = [];
        this.prompts = [];
        this.theme = 'void';

        this.init();
    }

    async init() {
        this.bindEvents();
        this.loadTheme();
        await this.loadOllamaStatus();
        await this.loadTasks();
        await this.loadNotes();
        await this.loadPrompts();
        await this.loadSessions();
        this.renderFileTree();
        // Open one terminal so the app is immediately usable.
        this.renderTerminalGrid();
        await this.createTerminal();
    }

    async loadPrompts() {
        try { this.prompts = await window.getPrompts() || []; } catch (e) { this.prompts = []; }
    }

    openSettings() {
        const modal = document.getElementById('settingsModal');
        window.getConfig().then(c => {
            document.getElementById('cfgModel').value = c.model || '';
            document.getElementById('cfgHost').value = c.host || '';
            document.getElementById('cfgTemp').value = c.temperature ?? 0.7;
            document.getElementById('cfgPerm').value = c.permissionMode || 'ask';
            document.getElementById('cfgSystem').value = c.systemPrompt || '';
        });
        this.renderPromptList();
        modal.classList.remove('hidden');
    }

    async saveSettings() {
        await window.setConfig({
            model: document.getElementById('cfgModel').value,
            host: document.getElementById('cfgHost').value,
            temperature: parseFloat(document.getElementById('cfgTemp').value) || 0.7,
            permissionMode: document.getElementById('cfgPerm').value,
            systemPrompt: document.getElementById('cfgSystem').value,
        });
        document.getElementById('settingsModal').classList.add('hidden');
    }

    renderPromptList() {
        const el = document.getElementById('promptList');
        if (!el) return;
        el.innerHTML = (this.prompts || []).map(p =>
            `<div class="prompt-item"><span title="${(p.body || '').replace(/"/g, '&quot;')}">${p.title}</span><button data-id="${p.id}" class="prompt-del">×</button></div>`
        ).join('') || '<div class="blocks-empty">No saved prompts</div>';
        el.querySelectorAll('.prompt-del').forEach(b =>
            b.addEventListener('click', async () => { await window.deletePrompt(b.dataset.id); await this.loadPrompts(); this.renderPromptList(); this.renderTerminalGrid(); }));
    }

    async addPrompt() {
        const t = document.getElementById('promptTitle');
        const b = document.getElementById('promptBody');
        if (!t.value.trim() || !b.value.trim()) return;
        await window.createPrompt(t.value.trim(), b.value.trim());
        t.value = ''; b.value = '';
        await this.loadPrompts();
        this.renderPromptList();
        this.renderTerminalGrid();
    }

    bindEvents() {
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => this.switchTab(tab.dataset.tab));
        });

        document.getElementById('addTab')?.addEventListener('click', () => this.addWorkspaceTab());

        document.querySelectorAll('.template-btn').forEach(btn => {
            btn.addEventListener('click', () => this.setPaneCount(parseInt(btn.dataset.panes)));
        });

        document.getElementById('newTerminal')?.addEventListener('click', () => this.createTerminal());

        document.getElementById('settingsBtn')?.addEventListener('click', () => this.openSettings());
        document.getElementById('settingsSave')?.addEventListener('click', () => this.saveSettings());
        document.getElementById('settingsClose')?.addEventListener('click', () => document.getElementById('settingsModal').classList.add('hidden'));
        document.getElementById('promptAdd')?.addEventListener('click', () => this.addPrompt());

        document.getElementById('themeSelect')?.addEventListener('change', (e) => this.setTheme(e.target.value));

        document.querySelectorAll('.add-card-btn').forEach(btn => {
            btn.addEventListener('click', () => this.showTaskModal(btn.dataset.status));
        });

        document.getElementById('newNote')?.addEventListener('click', () => this.createNote());

        document.getElementById('saveNote')?.addEventListener('click', () => this.saveNote());

        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'p') {
                e.preventDefault();
                this.showQuickOpen();
            }
            if (e.key === 'Escape') {
                this.hideModals();
            }
        });

        document.getElementById('quickOpenInput')?.addEventListener('input', (e) => this.searchFiles(e.target.value));
    }

    switchTab(tab) {
        this.currentTab = tab;
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

        document.querySelector(`.tab[data-tab="${tab}"]`)?.classList.add('active');
        document.getElementById(`${tab}-content`)?.classList.add('active');
    }

    loadTheme() {
        // Populate the dropdown from all available (inlined) themes.
        const select = document.getElementById('themeSelect');
        const themes = window.OLLAMADEV_THEMES ? Object.keys(window.OLLAMADEV_THEMES).sort() : [];
        if (select && themes.length) {
            select.innerHTML = themes.map(t =>
                `<option value="${t}">${t.charAt(0).toUpperCase() + t.slice(1).replace(/-/g, ' ')}</option>`
            ).join('');
        }
        const saved = localStorage.getItem('ollamadev-theme') || 'void';
        this.setTheme(saved);
        if (select) select.value = saved;
    }

    setTheme(theme) {
        this.theme = theme;
        if (window.setTheme) {
            window.setTheme(theme);
        } else {
            document.documentElement.dataset.theme = theme;
            localStorage.setItem('ollamadev-theme', theme);
        }
    }

    async ollamaStatus() {
        return window.ollamaStatus ? await window.ollamaStatus() : { connected: false, models: [] };
    }

    async loadOllamaStatus() {
        try {
            const data = await this.ollamaStatus();

            const indicator = document.querySelector('.status-indicator');
            if (indicator) {
                indicator.classList.toggle('connected', data.connected);
            }

            const modelSelect = document.getElementById('modelSelect');
            if (modelSelect && data.models) {
                modelSelect.innerHTML = data.models.map(m =>
                    `<option value="${m.name}">${m.name}</option>`
                ).join('');
            }
        } catch (e) {
            console.error('Failed to load Ollama status:', e);
        }
    }

    async loadTasks() {
        try {
            const data = await window.getTasks();
            this.tasks = Array.isArray(data) ? data : [];
            this.renderKanban();
        } catch (e) {
            console.error('Failed to load tasks:', e);
        }
    }

    async createTask(data) {
        try {
            const id = await window.createTask(data);
            await this.loadTasks();
            return id;
        } catch (e) {
            console.error('Failed to create task:', e);
        }
    }

    async updateTask(id, data) {
        try {
            await window.updateTask(id, data);
            await this.loadTasks();
        } catch (e) {
            console.error('Failed to update task:', e);
        }
    }

    async deleteTask(id) {
        try {
            await window.deleteTask(id);
            await this.loadTasks();
        } catch (e) {
            console.error('Failed to delete task:', e);
        }
    }

    renderKanban() {
        const columns = ['todo', 'inprogress', 'review', 'done'];
        columns.forEach(status => {
            const cards = this.tasks.filter(t => t.status === status);
            const containerId = status === 'todo' ? 'todo' : status === 'inprogress' ? 'inprogress' : status === 'review' ? 'review' : 'done';
            const container = document.getElementById(containerId + 'Cards');
            if (container) {
                container.innerHTML = cards.map(card => this.renderTaskCard(card)).join('');
                this.bindTaskCardEvents(container);
            }
        });
    }

    renderTaskCard(card) {
        return `
            <div class="task-card" data-id="${card.id}" draggable="true">
                <div class="task-card-header">
                    <span>${card.title}</span>
                    <span class="task-card-status ${card.status}">${card.status}</span>
                </div>
                ${card.description ? `<div class="task-card-description">${card.description}</div>` : ''}
                <div class="task-card-agent">🤖 ${card.agent || 'builder'}</div>
                <div class="task-card-actions">
                    <button class="task-card-btn run">Run</button>
                    <button class="task-card-btn">Edit</button>
                    <button class="task-card-btn delete">Delete</button>
                </div>
            </div>
        `;
    }

    bindTaskCardEvents(container) {
        container.querySelectorAll('.task-card').forEach(card => {
            card.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', card.dataset.id);
                card.classList.add('dragging');
            });

            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
            });

            card.querySelector('.run')?.addEventListener('click', () => this.runTask(card.dataset.id));
            card.querySelector('.delete')?.addEventListener('click', () => this.deleteTask(card.dataset.id));
        });
    }

    async runTask(id) {
        const task = this.tasks.find(t => t.id === id);
        if (!task) return;

        await this.updateTask(id, { status: 'inprogress' });

        // Open a terminal and dispatch the task to the agent running in it.
        const model = document.getElementById('modelSelect')?.value || 'llama3.2:latest';
        const tid = await window.createSession(model);
        this.terminals.set(tid, new TerminalPane(tid, model));
        this.switchTab('terminals');
        this.renderTerminalGrid();
        const prompt = task.title + (task.description ? ': ' + task.description : '');
        // Give the PTY daemon a moment to start before dispatching the agent.
        setTimeout(() => { try { window.agentRun(tid, prompt); } catch (e) { console.error(e); } }, 900);
        await this.loadSessions();
    }

    async openInEditor(path) {
        try {
            const r = await window.readFile(path);
            if (!r || r.error) { console.error(r && r.error); return; }
            this.switchTab('editor');
            const view = document.getElementById('editor-content');
            view.innerHTML = `
                <div class="editor-bar">
                    <span class="editor-path">${path}</span>
                    <span class="editor-status" id="editorStatus"></span>
                    <button class="editor-save" id="editorSave">Save</button>
                </div>
                <textarea class="editor-area" id="editorArea" spellcheck="false"></textarea>
            `;
            this._editorPath = path;
            const area = document.getElementById('editorArea');
            area.value = r.content || '';
            const markDirty = () => { document.getElementById('editorStatus').textContent = '● unsaved'; };
            area.addEventListener('input', markDirty);
            area.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const s = area.selectionStart, en = area.selectionEnd;
                    area.value = area.value.slice(0, s) + '    ' + area.value.slice(en);
                    area.selectionStart = area.selectionEnd = s + 4;
                    markDirty();
                } else if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                    e.preventDefault(); this.saveEditor();
                }
            });
            document.getElementById('editorSave').addEventListener('click', () => this.saveEditor());
        } catch (e) { console.error('openInEditor failed', e); }
    }

    async saveEditor() {
        if (!this._editorPath) return;
        const status = document.getElementById('editorStatus');
        const content = document.getElementById('editorArea')?.value ?? '';
        try {
            const r = await window.writeFile(this._editorPath, content);
            status.textContent = (r && r.error) ? '✗ ' + r.error : '✓ saved';
        } catch (e) { status.textContent = '✗ save failed'; }
    }

    showTaskModal(status = 'todo') {
        document.getElementById('taskModal').classList.remove('hidden');
    }

    async loadNotes() {
        try {
            const data = await window.getNotes();
            this.notes = Array.isArray(data) ? data : [];
            this.renderNotesList();
        } catch (e) {
            console.error('Failed to load notes:', e);
        }
    }

    renderNotesList() {
        const container = document.getElementById('notesList');
        if (!container) return;

        container.innerHTML = this.notes.map(note => `
            <div class="note-item" data-id="${note.id}">
                <div class="note-item-title">${note.title}</div>
                <div class="note-item-meta">${this.formatDate(note.updated)}</div>
                ${note.links > 0 ? `<div class="note-item-links">${note.links} links</div>` : ''}
            </div>
        `).join('');

        container.querySelectorAll('.note-item').forEach(item => {
            item.addEventListener('click', () => this.selectNote(item.dataset.id));
        });
    }

    async selectNote(id) {
        try {
            const note = await window.getNote(id);

            document.getElementById('noteContent').value = note.content || '';
            document.getElementById('noteMeta').textContent = `Links: ${note.backlinks?.length || 0} backlinks`;

            document.querySelectorAll('.note-item').forEach(n => n.classList.remove('active'));
            document.querySelector(`.note-item[data-id="${id}"]`)?.classList.add('active');
        } catch (e) {
            console.error('Failed to load note:', e);
        }
    }

    async createNote() {
        const title = prompt('Note title:');
        if (!title) return;

        try {
            const id = await window.createNote(title, `# ${title}\n\n`);
            await this.loadNotes();
            this.selectNote(id);
        } catch (e) {
            console.error('Failed to create note:', e);
        }
    }

    async saveNote() {
        const activeNote = document.querySelector('.note-item.active');
        if (!activeNote) return;

        const content = document.getElementById('noteContent').value;
        try {
            await window.updateNote(activeNote.dataset.id, { content });
            await this.loadNotes();
        } catch (e) {
            console.error('Failed to save note:', e);
        }
    }

    async loadSessions() {
        try {
            const data = await window.getSessions();
            this.renderSessions(Array.isArray(data) ? data : []);
        } catch (e) {
            console.error('Failed to load sessions:', e);
        }
    }

    renderSessions(sessions) {
        const container = document.getElementById('sessionList');
        if (!container) return;

        container.innerHTML = sessions.map(s => `
            <div class="session-item" data-id="${s.id}">
                <span>${s.model}</span>
                <span>${s.status}</span>
            </div>
        `).join('');
    }

    async createTerminal() {
        const model = document.getElementById('modelSelect')?.value || 'llama3.2:latest';
        try {
            const id = await window.createSession(model);
            if (!id || typeof id !== 'string') { setDiag('createSession returned ' + JSON.stringify(id), '#f85149'); return; }
            this.terminals.set(id, new TerminalPane(id, model));
            this.renderTerminalGrid();
            await this.loadSessions();
            setDiag('terminal ✓ ' + id.slice(-6), '#3fb950');
        } catch (e) {
            setDiag('terminal error: ' + (e && e.message ? e.message : e), '#f85149');
        }
    }

    closeTerminal(id) {
        const pane = this.terminals.get(id);
        if (pane) pane.close();
        this.terminals.delete(id);
        this.renderTerminalGrid();
    }

    async setPaneCount(count) {
        // Layout templates spawn terminals up to the requested count.
        this.paneCount = count;
        const model = document.getElementById('modelSelect')?.value || 'llama3.2:latest';
        while (this.terminals.size < count) {
            try {
                const id = await window.createSession(model);
                this.terminals.set(id, new TerminalPane(id, model));
            } catch (e) { console.error('Failed to create terminal:', e); break; }
        }
        this.renderTerminalGrid();
        await this.loadSessions();
    }

    getGridClass(count) {
        const classes = {
            1: 'single', 2: 'split', 4: 'quad', 6: 'six',
            8: 'eight', 12: 'twelve', 16: 'sixteen'
        };
        return classes[count] || 'single';
    }

    renderTerminalGrid() {
        const grid = document.getElementById('terminalGrid');
        const ids = [...this.terminals.keys()];
        grid.className = 'terminal-grid ' + this.getGridClass(Math.max(1, ids.length));
        grid.innerHTML = '';

        if (ids.length === 0) {
            grid.innerHTML = '<div class="terminal-empty">No terminals. Click “+ New Terminal”.</div>';
            return;
        }

        ids.forEach((id) => {
            const pane = this.terminals.get(id);
            const el = document.createElement('div');
            el.className = 'terminal-pane';
            el.innerHTML = `
                <div class="terminal-header">
                    <span>${pane.model} · ${id.slice(-6)}</span>
                    <span class="terminal-header-actions">
                        <button class="blocks-toggle" title="Command blocks">▤</button>
                        <button class="terminal-close" data-id="${id}">×</button>
                    </span>
                </div>
                <div class="terminal-main">
                    <div class="terminal-body"></div>
                    <div class="blocks-panel" hidden></div>
                </div>
                <form class="agent-bar">
                    <span class="agent-icon">🤖</span>
                    <select class="agent-prompts" title="Saved prompts">
                        <option value="">⌄</option>
                        ${(this.prompts || []).map(p => `<option value="${(p.body || '').replace(/"/g, '&quot;')}">${p.title}</option>`).join('')}
                    </select>
                    <input class="agent-input" type="text" autocomplete="off"
                           placeholder="Ask the agent to run something in this terminal…" />
                    <button type="submit" class="agent-run-btn">Run</button>
                </form>
            `;
            grid.appendChild(el);
            el.querySelector('.terminal-close').addEventListener('click', () => this.closeTerminal(id));
            const blocksPanel = el.querySelector('.blocks-panel');
            el.querySelector('.blocks-toggle').addEventListener('click', () => {
                blocksPanel.hidden = !blocksPanel.hidden;
                pane.setBlocksPanel(blocksPanel.hidden ? null : blocksPanel);
            });
            const agentForm = el.querySelector('.agent-bar');
            const agentInput = el.querySelector('.agent-input');
            const promptSel = el.querySelector('.agent-prompts');
            promptSel.addEventListener('change', () => {
                if (promptSel.value) { agentInput.value = promptSel.value; promptSel.value = ''; agentInput.focus(); }
            });
            agentForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const prompt = agentInput.value.trim();
                if (!prompt) return;
                agentInput.value = '';
                try { window.agentRun(id, prompt); } catch (err) { console.error(err); }
            });
            pane.mount(el.querySelector('.terminal-body'));
        });
    }

    async renderFileTree(path = null) {
        const container = document.getElementById('fileTree');
        if (!container) return;

        try {
            const data = await window.listFiles(path || '.');
            if (Array.isArray(data)) {
                container.innerHTML = data.map(item => `
                    <div class="tree-item ${item.type}" data-path="${item.path}">
                        <span class="tree-icon">${item.type === 'dir' ? '📁' : '📄'}</span>
                        <span>${item.name}</span>
                    </div>
                `).join('');

                container.querySelectorAll('.tree-item').forEach(item => {
                    item.addEventListener('click', () => {
                        if (item.classList.contains('dir')) {
                            this.renderFileTree(item.dataset.path);
                        } else {
                            this.openInEditor(item.dataset.path);
                        }
                    });
                });
            }
        } catch (e) {
            console.error('Failed to load files:', e);
        }
    }

    showQuickOpen() {
        document.getElementById('quickOpen').classList.remove('hidden');
        document.getElementById('quickOpenInput').focus();
    }

    async searchFiles(query) {
        if (!query) return;
        try {
            const data = await window.searchFiles(query);
            this.renderQuickOpenResults(Array.isArray(data) ? data : []);
        } catch (e) {
            console.error('Failed to search files:', e);
        }
    }

    renderQuickOpenResults(results) {
        const container = document.getElementById('quickOpenResults');
        if (!container) return;

        container.innerHTML = results.map(r => `
            <div class="quick-open-item" data-path="${r.path}">${r.name}</div>
        `).join('');
    }

    hideModals() {
        document.querySelectorAll('.modal').forEach(m => m.classList.add('hidden'));
    }

    formatDate(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diff = now - date;
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));

        if (days === 0) return 'Today';
        if (days === 1) return 'Yesterday';
        if (days < 7) return `${days}d ago`;
        return date.toLocaleDateString();
    }

    addWorkspaceTab() {
        console.log('Add workspace tab');
    }
}

// Encode a JS string (incl. UTF-8) to base64 for the binding boundary.
function strToB64(s) { return btoa(unescape(encodeURIComponent(s))); }
function b64ToStr(b64) {
    const bin = atob(b64);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    try { return new TextDecoder('utf-8', { fatal: false }).decode(arr); } catch (e) { return bin; }
}

// 16-color ANSI foreground palette.
const ANSI_FG = {
    30: '#3b4048', 31: '#f85149', 32: '#3fb950', 33: '#d29922', 34: '#58a6ff', 35: '#bc8cff', 36: '#39c5cf', 37: '#b1bac4',
    90: '#6e7681', 91: '#ff7b72', 92: '#56d364', 93: '#e3b341', 94: '#79c0ff', 95: '#d2a8ff', 96: '#56d4dd', 97: '#f0f6fc',
};

// Dependency-free terminal: streams PTY bytes from the daemon and renders them
// with ANSI SGR colors to the DOM. Full-screen TUIs (vim/htop) aren't supported,
// but command output, colors, and the agent running commands display cleanly.
class TerminalPane {
    constructor(id, model) {
        this.id = id; this.model = model;
        this.offset = 0; this.polling = false;
        this.screen = null; this.lineEl = null;
        this.fg = null; this.bold = false;
        this.blocksPanel = null;
    }

    mount(container) {
        this.offset = 0; this.lineEl = null; this.fg = null; this.bold = false;
        container.innerHTML = `
            <div class="term-screen" tabindex="0"></div>
            <form class="term-inputline">
                <span class="term-prompt">$</span>
                <input class="term-input" type="text" autocomplete="off" spellcheck="false" placeholder="type a command, Enter to run">
            </form>`;
        this.screen = container.querySelector('.term-screen');
        const input = container.querySelector('.term-input');
        const form = container.querySelector('.term-inputline');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            window.writeToSession(this.id, strToB64(input.value + '\n'));
            input.value = '';
        });
        input.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 'c') { e.preventDefault(); window.writeToSession(this.id, strToB64('\x03')); }
            else if (e.ctrlKey && e.key === 'd') { e.preventDefault(); window.writeToSession(this.id, strToB64('\x04')); }
        });
        this.screen.addEventListener('click', () => input.focus());
        if (!this.polling) this.startPolling();
    }

    setBlocksPanel(el) { this.blocksPanel = el; if (el) this.refreshBlocks(); }

    async refreshBlocks() {
        if (!this.blocksPanel) return;
        try {
            const blocks = await window.getBlocks(this.id);
            if (!Array.isArray(blocks)) return;
            this.blocksPanel.innerHTML = blocks.slice().reverse().map(b => {
                const running = b.exitCode === null || b.exitCode === undefined;
                const ok = !running && b.exitCode === 0;
                const icon = running ? '▶' : (ok ? '✓' : '✗');
                const cls = running ? 'running' : (ok ? 'ok' : 'fail');
                const dur = (b.endedAt && b.startedAt) ? ` ${b.endedAt - b.startedAt}s` : '';
                const code = running ? '' : ` exit ${b.exitCode}`;
                const cmd = (b.command || '').replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
                return `<div class="block ${cls}"><span class="block-icon">${icon}</span><code>${cmd}</code><span class="block-meta">${code}${dur}</span></div>`;
            }).join('') || '<div class="blocks-empty">No commands yet</div>';
        } catch (e) {}
    }

    newLine() {
        this.lineEl = document.createElement('div');
        this.lineEl.className = 'term-line';
        this.screen.appendChild(this.lineEl);
    }

    appendText(s) {
        if (!this.lineEl) this.newLine();
        const span = document.createElement('span');
        if (this.fg) span.style.color = this.fg;
        if (this.bold) span.style.fontWeight = 'bold';
        span.textContent = s;
        this.lineEl.appendChild(span);
    }

    applySgr(params) {
        const codes = params.split(';').filter(x => x !== '').map(Number);
        if (codes.length === 0) codes.push(0);
        for (const c of codes) {
            if (c === 0) { this.fg = null; this.bold = false; }
            else if (c === 1) this.bold = true;
            else if (c === 22) this.bold = false;
            else if (c === 39) this.fg = null;
            else if (ANSI_FG[c]) this.fg = ANSI_FG[c];
        }
    }

    write(text) {
        if (!this.screen) return;
        const atBottom = this.screen.scrollHeight - this.screen.scrollTop - this.screen.clientHeight < 40;
        let i = 0;
        while (i < text.length) {
            const ch = text[i];
            if (ch === '\x1b') {
                const csi = /^\x1b\[([0-9;?]*)([A-Za-z])/.exec(text.slice(i));
                if (csi) {
                    if (csi[2] === 'm') this.applySgr(csi[1]);
                    else if ((csi[2] === 'K' || csi[2] === 'J') && this.lineEl) this.lineEl.innerHTML = '';
                    i += csi[0].length; continue;
                }
                const osc = /^\x1b\][^\x07]*(?:\x07|\x1b\\)/.exec(text.slice(i));
                if (osc) { i += osc[0].length; continue; }
                i++; continue;
            }
            if (ch === '\r') { if (this.lineEl) this.lineEl.innerHTML = ''; i++; continue; }
            if (ch === '\n') { this.newLine(); i++; continue; }
            let j = i;
            while (j < text.length && text[j] !== '\x1b' && text[j] !== '\n' && text[j] !== '\r') j++;
            this.appendText(text.slice(i, j));
            i = j;
        }
        if (atBottom) this.screen.scrollTop = this.screen.scrollHeight;
    }

    startPolling() {
        this.polling = true;
        let tick = 0;
        const poll = async () => {
            if (!this.polling) return;
            try {
                const r = await window.readSession(this.id, this.offset);
                if (r && r.data) { this.write(b64ToStr(r.data)); this.offset = r.offset; }
            } catch (e) {}
            if (this.blocksPanel && (++tick % 12 === 0)) this.refreshBlocks();
            if (this.polling) setTimeout(poll, 80);
        };
        poll();
    }

    close() {
        this.polling = false;
        try { window.killSession(this.id); } catch (e) {}
    }
}

// Boson injects the PHP bindings (window.createSession, etc.) into the page
// asynchronously, which can race with DOMContentLoaded. Wait for a known
// binding to exist before starting the app so its first calls don't no-op.
function setDiag(msg, color) {
    const el = document.getElementById('statusDiag');
    if (el) { el.textContent = msg; if (color) el.style.color = color; }
}
window.onerror = (m, src, ln) => { setDiag('JS error: ' + m + ' @' + ln, '#f85149'); };

function startApp() {
    let waited = 0;
    const tick = () => {
        if (typeof window.createSession === 'function' && typeof window.getTasks === 'function') {
            setDiag('bindings ✓', '#3fb950');
            try { window.app = new App(); } catch (e) { setDiag('init error: ' + (e && e.message ? e.message : e), '#f85149'); }
        } else if (waited < 10000) {
            waited += 50;
            setDiag('waiting for bindings… ' + waited + 'ms', '#d29922');
            setTimeout(tick, 50);
        } else {
            setDiag('bindings NEVER appeared — window.createSession=' + (typeof window.createSession), '#f85149');
            try { window.app = new App(); } catch (e) {}
        }
    };
    tick();
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startApp);
} else {
    startApp();
}