class App {
    constructor() {
        this.currentTab = 'terminals';
        this.paneCount = 1;
        this.terminals = new Map();
        this.tasks = [];
        this.notes = [];
        this.theme = 'void';

        this.init();
    }

    async init() {
        this.bindEvents();
        this.loadTheme();
        await this.loadOllamaStatus();
        await this.loadTasks();
        await this.loadNotes();
        await this.loadSessions();
        this.renderFileTree();
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
        const saved = localStorage.getItem('ollamadev-theme') || 'void';
        this.setTheme(saved);
        document.getElementById('themeSelect').value = saved;
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
        this.createTerminal();
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
            this.terminals.set(id, new TerminalPane(id, model));
            this.renderTerminalGrid();
            await this.loadSessions();
        } catch (e) {
            console.error('Failed to create terminal:', e);
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
// Decode base64 to raw bytes for xterm (handles binary/ANSI/UTF-8 output).
function b64ToBytes(b64) {
    const bin = atob(b64);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return arr;
}

// A real shell terminal backed by the CLI's __pty-daemon__ (bash in a PTY).
// xterm renders output; keystrokes stream to the daemon's input queue.
class TerminalPane {
    constructor(id, model) {
        this.id = id;
        this.model = model;
        this.term = null;
        this.fit = null;
        this.offset = 0;   // bytes consumed from pty-out
        this.polling = false;
        this._ro = null;
        this.blocksPanel = null;
    }

    setBlocksPanel(el) {
        this.blocksPanel = el;
        if (el) this.refreshBlocks();
    }

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

    mount(container) {
        if (!window.Terminal) { container.textContent = 'xterm.js failed to load'; return; }
        if (this.term) { try { this.term.dispose(); } catch (e) {} }
        this.offset = 0; // replay full PTY output into the fresh element

        this.term = new window.Terminal({
            cursorBlink: true,
            fontSize: 13,
            fontFamily: "'SF Mono', 'Fira Code', monospace",
            theme: { background: '#0d1117', foreground: '#e6edf3' },
        });
        this.fit = new FitAddon.FitAddon();
        this.term.loadAddon(this.fit);
        this.term.open(container);
        try { this.fit.fit(); } catch (e) {}

        this.term.onData(d => window.writeToSession(this.id, strToB64(d)));
        this.term.onResize(({ cols, rows }) => { try { window.resizeSession(this.id, cols, rows); } catch (e) {} });

        if (window.ResizeObserver) {
            this._ro = new ResizeObserver(() => { try { this.fit.fit(); } catch (e) {} });
            this._ro.observe(container);
        }
        if (!this.polling) this.startPolling();
    }

    startPolling() {
        this.polling = true;
        let tick = 0;
        const poll = async () => {
            if (!this.polling) return;
            try {
                const r = await window.readSession(this.id, this.offset);
                if (r && r.data) { this.term.write(b64ToBytes(r.data)); this.offset = r.offset; }
            } catch (e) {}
            if (this.blocksPanel && (++tick % 16 === 0)) this.refreshBlocks(); // ~1/s
            if (this.polling) setTimeout(poll, 60);
        };
        poll();
    }

    close() {
        this.polling = false;
        if (this._ro) { this._ro.disconnect(); this._ro = null; }
        try { window.killSession(this.id); } catch (e) {}
        if (this.term) { try { this.term.dispose(); } catch (e) {} this.term = null; }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.app = new App();
});