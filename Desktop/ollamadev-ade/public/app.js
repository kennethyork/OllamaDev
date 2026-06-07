'use strict';

// ---------- helpers ----------
function $(sel) { return document.querySelector(sel); }
function banner(msg, kind) {
    var b = document.getElementById('banner');
    if (!b) return;
    b.textContent = 'OllamaDev: ' + msg;
    b.style.display = 'block';
    b.style.background = kind === 'ok' ? '#238636' : kind === 'err' ? '#b62324' : '#9a6700';
    if (kind === 'ok') setTimeout(function () { b.style.display = 'none'; }, 2000);
}
window.onerror = function (m, s, l) { banner('JS error: ' + m + ' @' + l, 'err'); return false; };

// Voice dictation — records the mic locally and transcribes via the configured
// local STT engine (window.sttTranscribe → CLI → SttClient). Inserts the text
// into a target field. Nothing leaves the machine. Toggle: click to start, click
// to stop. The button is shown only when an STT engine is configured.
var Voice = {
    rec: null, btn: null, target: null,
    // Reveal the mic button if a local STT engine is configured.
    init: function (btnId, targetId) {
        var btn = document.getElementById(btnId), target = document.getElementById(targetId);
        if (!btn || !target || !window.sttEnabled) return;
        Promise.resolve(window.sttEnabled()).then(function (on) {
            if (!on) return;
            btn.hidden = false;
            btn.onclick = function () { Voice.toggle(btn, target); };
        }).catch(function () {});
    },
    toggle: function (btn, target) {
        if (this.rec) { try { this.rec.stop(); } catch (e) {} return; } // stop → triggers onstop
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
            banner('this build has no microphone access', 'err'); return;
        }
        var self = this;
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var chunks = [], rec = new MediaRecorder(stream);
            rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
            rec.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                self.rec = null; btn.classList.remove('rec'); btn.textContent = '🎤';
                var fr = new FileReader();
                fr.onload = function () {
                    var b64 = String(fr.result).split(',')[1] || '';
                    if (!b64) { banner('no audio captured', 'err'); return; }
                    banner('transcribing…');
                    Promise.resolve(window.sttTranscribe(b64, 'webm')).then(function (text) {
                        text = (text || '').trim();
                        if (text) { target.value += (target.value && !/\s$/.test(target.value) ? ' ' : '') + text; target.focus(); banner('transcribed', 'ok'); }
                        else banner('no transcription — check your STT engine', 'err');
                    }).catch(function () { banner('transcription failed', 'err'); });
                };
                fr.readAsDataURL(new Blob(chunks, { type: 'audio/webm' }));
            };
            self.rec = rec; rec.start();
            btn.classList.add('rec'); btn.textContent = '⏹'; banner('recording… click ⏹ to stop');
        }).catch(function () { banner('microphone permission denied', 'err'); });
    }
};

// Tidy a long absolute path for a narrow label: collapse $HOME to ~ and keep the
// meaningful tail (last segments) instead of the start, e.g.
//   /home/me/Documents/OllamaDev/Desktop/ollamadev-ade  ->  ~/…/Desktop/ollamadev-ade
function shortPath(p, maxLen) {
    p = String(p || ''); maxLen = maxLen || 38;
    var home = (window.HOME_DIR || '');
    if (home && (p === home || p.indexOf(home + '/') === 0)) p = '~' + p.slice(home.length);
    if (p.length <= maxLen) return p;
    var lead = p[0] === '~' ? '~' : '';
    var parts = p.replace(/^~?\/?/, '').split('/').filter(Boolean);
    var tail = parts.slice(-2).join('/');
    return (lead ? lead + '/' : '') + '…/' + tail;
}

function strToB64(s) { return btoa(unescape(encodeURIComponent(s))); }
function b64ToStr(b64) {
    var bin = atob(b64), arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    try { return new TextDecoder('utf-8', { fatal: false }).decode(arr); } catch (e) { return bin; }
}
function rid() { return 'term_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8); }

var ANSI_FG = { 30:'#484f58',31:'#f85149',32:'#3fb950',33:'#d29922',34:'#58a6ff',35:'#bc8cff',36:'#39c5cf',37:'#b1bac4',90:'#6e7681',91:'#ff7b72',92:'#56d364',93:'#e3b341',94:'#79c0ff',95:'#d2a8ff',96:'#56d4dd',97:'#f0f6fc' };

// Map a browser keydown to the byte sequence a real terminal would send, so the
// pty (and the raw-mode ollamadev CLI inside it) gets live keystrokes.
function keyToBytes(e) {
    if (e.altKey) return null;
    var k = e.key;
    if (e.ctrlKey) {
        if (k === 'c') return '\x03';
        if (k === 'd') return '\x04';
        if (k === 'z') return '\x1a';
        if (k.length === 1) {
            var c = k.toLowerCase().charCodeAt(0);
            if (c >= 97 && c <= 122) return String.fromCharCode(c - 96); // Ctrl-A..Z
        }
        return null;
    }
    switch (k) {
        case 'Enter': return '\r';
        case 'Backspace': return '\x7f';
        case 'Tab': return '\t';
        case 'Escape': return '\x1b';
        case 'ArrowUp': return '\x1b[A';
        case 'ArrowDown': return '\x1b[B';
        case 'ArrowRight': return '\x1b[C';
        case 'ArrowLeft': return '\x1b[D';
        case 'Home': return '\x1b[H';
        case 'End': return '\x1b[F';
        case 'Delete': return '\x1b[3~';
        case 'PageUp': return '\x1b[5~';
        case 'PageDown': return '\x1b[6~';
    }
    if (k.length === 1) return k; // a printable character
    return null;
}

// Build a "by project type" crew team (composition + domain focus).
function D(name, focus, max) {
    return { name: name, group: 'domain', max: max || 2, researcher: true, auditor: true, review: true, focus: focus };
}

// ---------- terminal pane (dependency-free, ANSI colors) ----------
function Terminal(id, model, cwd) {
    this.id = id; this.model = model; this.cwd = cwd || ''; this.offset = 0; this.polling = false;
    this.screen = null; this.line = null; this.fg = null; this.bold = false;
    this.status = 'idle'; this.lastData = 0; this.badgeEl = null; this.cr = false;
}
Terminal.prototype.mount = function (host) {
    var self = this;
    this.offset = 0; this.line = null; this.fg = null; this.bold = false; this.cr = false;
    host.innerHTML =
        '<div class="term-head"><span class="nm">' + esc(this.model) + '</span><span class="id">' + this.id.slice(-6) + '</span>' +
        '<span class="badge ' + this.status + '"><span class="b-dot"></span><span class="b-label">' + this.status + '</span></span>' +
        '<button class="term-cd" title="Working folder — click to change">📁 ' + esc(this.cwd ? (this.cwd.split('/').filter(Boolean).pop() || '/') : 'project') + '</button>' +
        '<button class="zoom" title="Focus (make this terminal bigger)">⤢</button>' +
        '<button class="x" title="Close">&times;</button></div>' +
        '<div class="term-screen" tabindex="0" title="Click and type — this is the live ollamadev CLI"></div>' +
        // Touch input — shown only on small screens (web mode on a phone/tablet).
        // The raw PTY is awful with a soft keyboard, so type a line here + Enter,
        // and use the key bar for control keys a line input can't send.
        '<div class="term-touch">' +
          '<div class="term-keys">' +
            '<button data-k="tab">Tab</button><button data-k="esc">Esc</button>' +
            '<button data-k="up">↑</button><button data-k="down">↓</button>' +
            '<button data-k="cc">Ctrl-C</button><button data-k="cd">Ctrl-D</button>' +
          '</div>' +
          '<div class="term-line"><input class="term-input" enterkeyhint="send" autocapitalize="off" autocomplete="off" spellcheck="false" placeholder="type a command — Enter to send"><button class="term-send">Send</button></div>' +
        '</div>';
    this.screen = host.querySelector('.term-screen');
    // Wire the touch input: send the typed line (+newline) and the control keys.
    var sendRaw = function (s) { try { window.termWrite(self.id, strToB64(s)); } catch (e) {} };
    var tin = host.querySelector('.term-input');
    var sendLine = function () { sendRaw(tin.value + '\n'); tin.value = ''; tin.focus(); };
    tin.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sendLine(); } });
    host.querySelector('.term-send').onclick = sendLine;
    var KEYS = { tab: '\t', esc: '\x1b', up: '\x1b[A', down: '\x1b[B', cc: '\x03', cd: '\x04' };
    host.querySelectorAll('.term-keys button').forEach(function (b) {
        b.onclick = function () { sendRaw(KEYS[b.dataset.k] || ''); tin.focus(); };
    });
    this.badgeEl = host.querySelector('.badge');
    var head = host.querySelector('.term-head');
    head.title = 'Double-click to focus / restore';
    head.ondblclick = function (e) { if (e.target.classList.contains('x') || e.target.classList.contains('zoom')) return; app.toggleZoom(self.id); };
    // Focus button: enlarge this terminal to fill the area; click again to restore
    // it back into the grid with the rest. Reflects the current state per render.
    var zb = host.querySelector('.zoom');
    var focused = app.zoomed === self.id;
    zb.textContent = focused ? '⤡' : '⤢';
    zb.title = focused ? 'Restore (back to the other terminals)' : 'Focus (make this terminal bigger)';
    zb.onclick = function (e) { e.stopPropagation(); app.toggleZoom(self.id); };
    host.querySelector('.x').onclick = function () { app.closeTerminal(self.id); };
    // Folder chip: click to edit this terminal's working folder inline. Enter respawns
    // it in the new directory; Esc/blur cancels. Each terminal can run in its own folder.
    var cd = host.querySelector('.term-cd');
    if (cd) cd.onclick = function (e) {
        e.stopPropagation();
        var cur = self.cwd || (app.cwd && app.cwd !== '.' ? app.cwd : '');
        var inp = document.createElement('input');
        inp.className = 'field mono term-cd-edit'; inp.value = cur; inp.placeholder = 'folder path (~ ok)';
        cd.replaceWith(inp); inp.focus(); inp.select();
        var done = false;
        var finish = function (commit) {
            if (done) return; done = true;
            var v = inp.value.trim();
            if (commit && v && v !== cur) app.changeTermFolder(self.id, v); else app.render();
        };
        inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); finish(true); } else if (ev.key === 'Escape') finish(false); });
        inp.addEventListener('blur', function () { finish(false); });
    };
    // The screen IS the terminal: forward every keystroke straight to the pty.
    this.screen.addEventListener('keydown', function (e) {
        var data = keyToBytes(e);
        if (data !== null) { e.preventDefault(); try { window.termWrite(self.id, strToB64(data)); } catch (err) {} }
    });
    this.screen.addEventListener('paste', function (e) {
        var t = (e.clipboardData || window.clipboardData).getData('text');
        if (t) { e.preventDefault(); try { window.termWrite(self.id, strToB64(t)); } catch (err) {} }
    });
    this.screen.onclick = function () { self.screen.focus(); };
    if (!this.polling) this.poll();
    setTimeout(function () { self.screen.focus(); }, 0);
};
Terminal.prototype.setStatus = function (s) {
    this.status = s;
    if (this.badgeEl) {
        this.badgeEl.className = 'badge ' + s;
        var lbl = this.badgeEl.querySelector('.b-label'); if (lbl) lbl.textContent = s;
    }
};
Terminal.prototype.newLine = function () { this.line = document.createElement('div'); this.line.className = 'term-line'; this.screen.appendChild(this.line); };
// Remove the last character of the current line (handles the pty's \b \b erase).
Terminal.prototype.backspace = function () {
    if (!this.line) return;
    var nodes = this.line.childNodes;
    for (var k = nodes.length - 1; k >= 0; k--) {
        var sp = nodes[k], t = sp.textContent || '';
        if (t.length > 0) { sp.textContent = t.slice(0, -1); if (sp.textContent === '') this.line.removeChild(sp); return; }
        this.line.removeChild(sp);
    }
};
Terminal.prototype.emit = function (s) {
    if (!this.line) this.newLine();
    // A pending carriage return overwrites the current line (e.g. progress bars),
    // but a bare \r\n must NOT erase the line — that's handled by clearing here
    // only when actual text follows the \r.
    if (this.cr) { this.line.innerHTML = ''; this.cr = false; }
    // Drop leftover C0 control bytes (bell, NUL, etc.) so they never render as
    // tofu/□ boxes — keep tab. \r \n \x08 \x7f \x1b are handled before emit().
    s = s.replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '');
    if (s === '') return;
    var sp = document.createElement('span');
    if (this.fg) sp.style.color = this.fg;
    if (this.bold) sp.style.fontWeight = '700';
    sp.textContent = s; this.line.appendChild(sp);
};
Terminal.prototype.sgr = function (p) {
    var codes = p.split(';').filter(function (x) { return x !== ''; }).map(Number);
    if (!codes.length) codes = [0];
    for (var i = 0; i < codes.length; i++) {
        var c = codes[i];
        if (c === 0) { this.fg = null; this.bold = false; }
        else if (c === 1) this.bold = true;
        else if (c === 22) this.bold = false;
        else if (c === 39) this.fg = null;
        else if (ANSI_FG[c]) this.fg = ANSI_FG[c];
    }
};
Terminal.prototype.write = function (text) {
    if (!this.screen) return;
    var bottom = this.screen.scrollHeight - this.screen.scrollTop - this.screen.clientHeight < 50;
    var i = 0;
    while (i < text.length) {
        var ch = text[i];
        if (ch === '\x1b') {
            var csi = /^\x1b\[([0-9;?]*)([A-Za-z])/.exec(text.slice(i));
            if (csi) { if (csi[2] === 'm') this.sgr(csi[1]); else if ((csi[2] === 'K' || csi[2] === 'J') && this.line) this.line.innerHTML = ''; i += csi[0].length; continue; }
            var osc = /^\x1b\][^\x07]*(?:\x07|\x1b\\)/.exec(text.slice(i));
            if (osc) { i += osc[0].length; continue; }
            i++; continue;
        }
        if (ch === '\r') { this.cr = true; i++; continue; }
        if (ch === '\n') { this.cr = false; this.newLine(); i++; continue; }
        if (ch === '\x08' || ch === '\x7f') { this.backspace(); i++; continue; } // erase (pty echoes \b \b / DEL)
        // Scan a run of plain text. Stop at any control byte we handle above —
        // critically \x08/\x7f, so a backspace erase isn't emitted as a literal
        // (tofu) control glyph, which both broke the erase and looked like a
        // font problem in the desktop/web terminal.
        var j = i;
        while (j < text.length && text[j] !== '\x1b' && text[j] !== '\n' && text[j] !== '\r'
               && text[j] !== '\x08' && text[j] !== '\x7f') j++;
        this.emit(text.slice(i, j)); i = j;
    }
    if (bottom) this.screen.scrollTop = this.screen.scrollHeight;
};
Terminal.prototype.poll = function () {
    var self = this; this.polling = true;
    function tick() {
        if (!self.polling) return;
        Promise.resolve(window.termRead(self.id, self.offset)).then(function (r) {
            if (r && r.data) { self.write(b64ToStr(r.data)); self.offset = r.offset; self.lastData = Date.now(); }
            // Agent "running" → "done" once output has been quiet for ~2.5s.
            if (self.status === 'running' && self.lastData && Date.now() - self.lastData > 2500) self.setStatus('done');
        }).catch(function () {}).then(function () { if (self.polling) setTimeout(tick, 80); });
    }
    tick();
};
Terminal.prototype.close = function () { this.polling = false; try { window.termKill(this.id); } catch (e) {} };
// Detach the UI but KEEP the backend pty alive (used when switching workspaces),
// so re-attaching later resumes the same running session.
Terminal.prototype.detach = function () { this.polling = false; };

function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

// ---------- code editor (tabs + line-numbered textarea, vanilla) ----------
var Editor = {
    tabs: [], active: -1, mounted: null,
    cur: function () { return this.active >= 0 ? this.tabs[this.active] : null; },
    open: function (path, name) {
        var self = this;
        var i = this.tabs.findIndex(function (t) { return t.path === path; });
        if (i >= 0) { this.active = i; this.render(); App.markActiveFile(path); return; }
        Promise.resolve(window.readFile(path)).then(function (r) {
            if (!r || r.error) { banner('open failed: ' + ((r && r.error) || 'unknown'), 'err'); return; }
            self.tabs.push({ path: path, name: name || path.split('/').pop(), content: String(r.content == null ? '' : r.content), dirty: false });
            self.active = self.tabs.length - 1;
            self.render();
            App.markActiveFile(path);
        }).catch(function (e) { banner('open error: ' + e, 'err'); });
    },
    save: function () {
        var t = this.cur(); if (!t) return;
        var ta = document.querySelector('#editorBody .ed-text'); if (ta) t.content = ta.value;
        Promise.resolve(window.writeFile(t.path, t.content)).then(function (r) {
            if (r && r.success) { t.dirty = false; Editor.renderTabs(); Editor.refreshStatus(); banner('saved ' + t.name, 'ok'); }
            else banner('save failed: ' + ((r && r.error) || 'unknown'), 'err');
        }).catch(function (e) { banner('save error: ' + e, 'err'); });
    },
    close: function (i) {
        var t = this.tabs[i];
        if (t && t.dirty && !window.confirm('Discard unsaved changes to ' + t.name + '?')) return;
        this.tabs.splice(i, 1);
        if (this.active >= this.tabs.length) this.active = this.tabs.length - 1;
        this.mounted = null;
        this.render();
        var c = this.cur(); App.markActiveFile(c ? c.path : null);
    },
    // Close every tab without prompting — used when switching workspaces (the
    // outgoing workspace's open files are already saved in its state).
    closeAll: function () { this.tabs = []; this.active = -1; this.mounted = null; this.render(); },
    renderTabs: function () {
        var bar = $('#editorTabs'); var self = this; if (!bar) return;
        bar.innerHTML = '';
        this.tabs.forEach(function (t, i) {
            var el = document.createElement('div');
            el.className = 'etab' + (i === self.active ? ' active' : '') + (t.dirty ? ' dirty' : '');
            el.innerHTML = '<span class="dot">•</span><span class="nm">' + esc(t.name) + '</span><button class="xc" title="Close">×</button>';
            el.querySelector('.nm').onclick = function () { self.active = i; self.render(); App.markActiveFile(t.path); };
            el.querySelector('.xc').onclick = function (e) { e.stopPropagation(); self.close(i); };
            bar.appendChild(el);
        });
    },
    refreshStatus: function () {
        var t = this.cur(); var st = document.querySelector('#editorBody .ed-status'); var ta = document.querySelector('#editorBody .ed-text');
        if (!t || !st || !ta) return;
        var n = ta.value.split('\n').length;
        st.textContent = (t.dirty ? '● ' : '') + t.name + ' — ' + n + ' lines  (Ctrl+S to save)';
    },
    render: function () {
        this.renderTabs();
        var body = $('#editorBody'); if (!body) return;
        var t = this.cur();
        if (!t) { body.innerHTML = '<div id="editorEmpty" class="dim">Select a file from the sidebar to edit it.</div>'; this.mounted = null; return; }
        if (this.mounted !== t.path) {
            body.innerHTML = '<div class="ed-gutter"></div><textarea class="ed-text" spellcheck="false" wrap="off"></textarea><div class="ed-status"></div>';
            var ta = body.querySelector('.ed-text'), gut = body.querySelector('.ed-gutter');
            ta.value = t.content;
            var sync = function () {
                var n = ta.value.split('\n').length, g = '';
                for (var k = 1; k <= n; k++) g += k + '\n';
                gut.textContent = g;
                Editor.refreshStatus();
            };
            ta.addEventListener('input', function () {
                t.content = ta.value;
                if (!t.dirty) { t.dirty = true; Editor.renderTabs(); }
                sync();
            });
            ta.addEventListener('scroll', function () { gut.scrollTop = ta.scrollTop; });
            ta.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); Editor.save(); return; }
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var s = ta.selectionStart, en = ta.selectionEnd;
                    ta.value = ta.value.slice(0, s) + '    ' + ta.value.slice(en);
                    ta.selectionStart = ta.selectionEnd = s + 4;
                    t.content = ta.value; if (!t.dirty) { t.dirty = true; Editor.renderTabs(); } sync();
                }
            });
            this.mounted = t.path;
            sync(); ta.focus();
        }
    }
};

// ---------- app ----------
// ---------- kanban tasks (local, hands work to agent terminals) ----------
var Tasks = {
    items: [], COLS: [['todo', 'To-do'], ['doing', 'Doing'], ['done', 'Done']],
    load: function () { try { this.items = JSON.parse(localStorage.getItem('ade.tasks') || '[]'); } catch (e) { this.items = []; } if (!Array.isArray(this.items)) this.items = []; },
    save: function () { try { localStorage.setItem('ade.tasks', JSON.stringify(this.items)); } catch (e) {} },
    add: function (title) {
        title = (title || '').trim(); if (!title) return;
        this.items.push({ id: 't' + Date.now() + Math.random().toString(36).slice(2, 6), title: title, col: 'todo' });
        this.save(); this.render();
    },
    move: function (id, col) { var t = this.items.find(function (x) { return x.id === id; }); if (t) { t.col = col; this.save(); this.render(); } },
    del: function (id) { this.items = this.items.filter(function (x) { return x.id !== id; }); this.save(); this.render(); },
    run: function (id) { var t = this.items.find(function (x) { return x.id === id; }); if (!t) return; this.move(id, 'doing'); App.runTaskInAgent(t.title); },
    // Map a crew subtask state to a board column (held shows in Doing, flagged).
    crewCol: function (state) { return state === 'done' ? 'done' : (state === 'todo' ? 'todo' : 'doing'); },
    render: function () {
        var board = $('#board'); if (!board) return;
        var self = this;
        var crew = (App.crewBoard && Array.isArray(App.crewBoard.subtasks)) ? App.crewBoard.subtasks : [];
        // Crew's auto-suggested next steps (ideas) land in To-do as 💡 cards.
        var ideas = (App.crewBoard && Array.isArray(App.crewBoard.ideas)) ? App.crewBoard.ideas : [];
        // Separate Director: show the steering bar only while a run is active.
        var dbar = $('#directorBar'); if (dbar) dbar.hidden = !(App.crewBoard && App.crewBoard.active);
        this.wireDirector();
        board.innerHTML = this.COLS.map(function (c) {
            // Director's plan as live, read-only crew cards.
            var crewInCol = crew.filter(function (s) { return self.crewCol(s.state) === c[0]; });
            var crewCards = crewInCol.map(function (s) {
                var held = s.state === 'held';
                // The Director tags each subtask with a role (coder/tester/docs/…); show it.
                var role = (s.role && s.role !== 'coder') ? '<span class="role">' + esc(s.role) + '</span>' : '';
                return '<div class="card crew' + (held ? ' held' : '') + '">' +
                    '<div class="title">🤖 ' + esc(s.title) + role + '</div>' +
                    '<div class="cmeta">' + (held ? '⚠ held' : (s.state === 'doing' ? '● working' : s.state)) + '</div></div>';
            }).join('');
            // Suggested next-step ideas show as To-do cards you can run with one click.
            var ideaCards = c[0] !== 'todo' ? '' : ideas.map(function (idea) {
                return '<div class="card idea"><div class="title">💡 ' + esc(idea) + '</div>' +
                    '<div class="actions"><button class="run" data-act="run-idea" data-idea="' + esc(idea) + '">▶ Run</button></div></div>';
            }).join('');
            // The user's own manual tasks.
            var cards = self.items.filter(function (t) { return t.col === c[0]; });
            var manual = cards.map(function (t) {
                return '<div class="card" draggable="true" data-id="' + t.id + '">' +
                    '<div class="title">' + esc(t.title) + '</div>' +
                    '<div class="actions">' +
                    (c[0] !== 'done' ? '<button class="run" data-act="run">▶ Run</button>' : '') +
                    '<button data-act="back">◀</button><button data-act="fwd">▶</button>' +
                    '<button class="del" data-act="del">✕</button></div></div>';
            }).join('');
            var body = (crewCards + ideaCards + manual) || '<div class="board-empty">—</div>';
            var count = crewInCol.length + cards.length + (c[0] === 'todo' ? ideas.length : 0);
            return '<div class="col" data-col="' + c[0] + '"><div class="col-head"><span class="dotc"></span>' + c[1] +
                '<span class="count">' + count + '</span></div><div class="col-body">' + body + '</div></div>';
        }).join('');
        this.wire(board);
    },
    // Wire the separate-Director steering bar once. Parses "<#>: instruction"
    // (defaults to coder 1 if no number) and sends it to the running crew.
    wireDirector: function () {
        var send = $('#directorSend'), msg = $('#directorMsg');
        if (!send || !msg || send._wired) return;
        send._wired = true;
        var fire = function () {
            var v = (msg.value || '').trim(); if (!v) return;
            var m = v.match(/^(\d+|all|\*|everyone)\s*[:>\-]\s*(.+)$/i);
            var coder = m ? (/^\d+$/.test(m[1]) ? parseInt(m[1], 10) : 0) : 1;   // 0 = whole crew
            var text = m ? m[2].trim() : v;
            Promise.resolve(window.crewSteer ? window.crewSteer(coder, text) : null).then(function (r) {
                msg.value = '';
                msg.placeholder = (r && r.error) ? ('⚠ ' + r.error) : ('✓ sent to ' + (coder === 0 ? 'the crew' : 'coder ' + coder));
                setTimeout(function () { msg.placeholder = 'Steer a coder — e.g. "2: focus on tests" or "all: ..."'; }, 2500);
            });
        };
        send.onclick = fire;
        msg.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); fire(); } });
    },
    order: ['todo', 'doing', 'done'],
    wire: function (board) {
        var self = this;
        // Suggested-idea cards aren't in the manual list — hand the idea straight
        // to an agent terminal (no id lookup).
        board.querySelectorAll('[data-act="run-idea"]').forEach(function (btn) {
            btn.onclick = function () { App.runTaskInAgent(btn.dataset.idea); };
        });
        board.querySelectorAll('.card').forEach(function (card) {
            var id = card.dataset.id;
            if (!id) return; // idea/crew cards have no manual id
            card.addEventListener('dragstart', function (e) { card.classList.add('dragging'); e.dataTransfer.setData('text/plain', id); });
            card.addEventListener('dragend', function () { card.classList.remove('dragging'); });
            card.querySelectorAll('[data-act]').forEach(function (b) {
                b.onclick = function () {
                    var t = self.items.find(function (x) { return x.id === id; }); if (!t) return;
                    var i = self.order.indexOf(t.col);
                    if (b.dataset.act === 'run') self.run(id);
                    else if (b.dataset.act === 'del') self.del(id);
                    else if (b.dataset.act === 'fwd' && i < 2) self.move(id, self.order[i + 1]);
                    else if (b.dataset.act === 'back' && i > 0) self.move(id, self.order[i - 1]);
                };
            });
        });
        board.querySelectorAll('.col').forEach(function (col) {
            col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('drag-over'); });
            col.addEventListener('dragleave', function () { col.classList.remove('drag-over'); });
            col.addEventListener('drop', function (e) {
                e.preventDefault(); col.classList.remove('drag-over');
                var id = e.dataTransfer.getData('text/plain'); if (id) self.move(id, col.dataset.col);
            });
        });
    }
};

// Project memory graph — a tiny vanilla force-directed layout on a canvas.
var Graph = {
    nodes: [], edges: [], anim: null, hover: null, dragging: null, dpr: 1,
    load: function () {
        var self = this;
        Promise.resolve(window.memoryGraph ? window.memoryGraph() : { nodes: [], edges: [] }).then(function (g) {
            g = g || {}; var nodes = g.nodes || [], edges = g.edges || [];
            $('#graphStat').textContent = nodes.length + ' notes · ' + edges.length + ' links';
            $('#graphEmpty').hidden = nodes.length > 0;
            $('#graphCanvas').hidden = nodes.length === 0;
            self.build(nodes, edges);
        }).catch(function () { $('#graphEmpty').hidden = false; });
    },
    build: function (nodes, edges) {
        var cv = $('#graphCanvas'); if (!cv) return;
        var w = cv.clientWidth || 800, h = cv.clientHeight || 500;
        // seed positions on a circle (deterministic — no Math.random needed)
        this.nodes = nodes.map(function (n, i) {
            var a = (i / Math.max(1, nodes.length)) * Math.PI * 2;
            return { id: n.id, title: n.title || n.id, tags: n.tags || [], degree: n.degree || 0,
                x: w / 2 + Math.cos(a) * Math.min(w, h) * 0.3, y: h / 2 + Math.sin(a) * Math.min(w, h) * 0.3, vx: 0, vy: 0 };
        });
        var byId = {}; this.nodes.forEach(function (n) { byId[n.id] = n; });
        this.edges = (edges || []).filter(function (e) { return byId[e.from] && byId[e.to]; });
        this.byId = byId;
        this.resize();
        this.start();
    },
    resize: function () {
        var cv = $('#graphCanvas'); if (!cv) return;
        this.dpr = window.devicePixelRatio || 1;
        cv.width = cv.clientWidth * this.dpr; cv.height = cv.clientHeight * this.dpr;
    },
    start: function () {
        var self = this; if (this.anim) cancelAnimationFrame(this.anim);
        var ticks = 0;
        var step = function () {
            var settle = self.tick();
            self.draw();
            ticks++;
            // keep animating while the user interacts; otherwise stop once settled
            if (self.dragging || (ticks < 600 && settle > 0.05)) self.anim = requestAnimationFrame(step);
            else self.anim = null;
        };
        step();
    },
    tick: function () {
        var cv = $('#graphCanvas'); var w = cv.clientWidth, h = cv.clientHeight;
        var ns = this.nodes, i, j, energy = 0;
        var k = 0.012, rep = 9000, spring = 0.02, restLen = 120, damp = 0.85;
        for (i = 0; i < ns.length; i++) { ns[i].fx = (w / 2 - ns[i].x) * k; ns[i].fy = (h / 2 - ns[i].y) * k; }
        for (i = 0; i < ns.length; i++) for (j = i + 1; j < ns.length; j++) {
            var dx = ns[i].x - ns[j].x, dy = ns[i].y - ns[j].y, d2 = dx * dx + dy * dy + 0.01;
            var f = rep / d2, d = Math.sqrt(d2), ux = dx / d, uy = dy / d;
            ns[i].fx += ux * f; ns[i].fy += uy * f; ns[j].fx -= ux * f; ns[j].fy -= uy * f;
        }
        this.edges.forEach(function (e) {
            var a = this.byId[e.from], b = this.byId[e.to];
            var dx = b.x - a.x, dy = b.y - a.y, d = Math.sqrt(dx * dx + dy * dy) + 0.01;
            var f = (d - restLen) * spring, ux = dx / d, uy = dy / d;
            a.fx += ux * f; a.fy += uy * f; b.fx -= ux * f; b.fy -= uy * f;
        }, this);
        for (i = 0; i < ns.length; i++) {
            if (ns[i] === this.dragging) { ns[i].vx = 0; ns[i].vy = 0; continue; }
            ns[i].vx = (ns[i].vx + ns[i].fx) * damp; ns[i].vy = (ns[i].vy + ns[i].fy) * damp;
            ns[i].x += ns[i].vx; ns[i].y += ns[i].vy;
            energy += Math.abs(ns[i].vx) + Math.abs(ns[i].vy);
        }
        return ns.length ? energy / ns.length : 0;
    },
    draw: function () {
        var cv = $('#graphCanvas'); if (!cv) return; var ctx = cv.getContext('2d');
        var css = getComputedStyle(document.body);
        var line = css.getPropertyValue('--border') || '#444', accent = css.getPropertyValue('--accent') || '#4ea1ff';
        var fg = css.getPropertyValue('--fg') || '#ddd', panel = css.getPropertyValue('--bg3') || '#222';
        ctx.save(); ctx.scale(this.dpr, this.dpr);
        ctx.clearRect(0, 0, cv.clientWidth, cv.clientHeight);
        ctx.strokeStyle = line; ctx.lineWidth = 1; ctx.globalAlpha = 0.7;
        this.edges.forEach(function (e) {
            var a = this.byId[e.from], b = this.byId[e.to];
            ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
        }, this);
        ctx.globalAlpha = 1;
        this.nodes.forEach(function (n) {
            var r = 6 + Math.min(14, n.degree * 2);
            ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2);
            ctx.fillStyle = (n === this.hover) ? accent : panel; ctx.fill();
            ctx.lineWidth = 2; ctx.strokeStyle = accent; ctx.stroke();
            ctx.fillStyle = fg; ctx.font = '12px system-ui, sans-serif'; ctx.textAlign = 'center';
            ctx.fillText(n.title, n.x, n.y + r + 13);
        }, this);
        ctx.restore();
    },
    nodeAt: function (mx, my) {
        for (var i = this.nodes.length - 1; i >= 0; i--) {
            var n = this.nodes[i], r = 6 + Math.min(14, n.degree * 2) + 4;
            if ((mx - n.x) * (mx - n.x) + (my - n.y) * (my - n.y) <= r * r) return n;
        }
        return null;
    },
    bind: function () {
        var self = this, cv = $('#graphCanvas'); if (!cv) return;
        var pos = function (ev) { var rc = cv.getBoundingClientRect(); return { x: ev.clientX - rc.left, y: ev.clientY - rc.top }; };
        cv.addEventListener('mousedown', function (ev) { var p = pos(ev); self.dragging = self.nodeAt(p.x, p.y); if (self.dragging && !self.anim) self.start(); });
        cv.addEventListener('mousemove', function (ev) {
            var p = pos(ev);
            if (self.dragging) { self.dragging.x = p.x; self.dragging.y = p.y; }
            else { var h = self.nodeAt(p.x, p.y); if (h !== self.hover) { self.hover = h; cv.style.cursor = h ? 'pointer' : 'default'; if (!self.anim) self.draw(); } }
        });
        window.addEventListener('mouseup', function () { self.dragging = null; });
        var rb = $('#graphRefresh'); if (rb) rb.onclick = function () { self.load(); };
        window.addEventListener('resize', function () { if (App.view === 'graph') { self.resize(); if (!self.anim) self.draw(); } });
    }
};

// Live per-coder panes: one read-only terminal-style pane per crew coder,
// tailing its log so you watch the whole team build in parallel.
var CrewPanes = {
    runId: null, offsets: {}, text: {}, bodies: {}, count: 0, zoomed: null,
    sync: function (board) {
        var host = $('#crewPanes'); if (!host) return;
        var subs = (board && Array.isArray(board.subtasks)) ? board.subtasks : [];
        if (!board || !board.runId || !subs.length) { host.hidden = true; host.innerHTML = ''; this.runId = null; this.count = 0; this.zoomed = null; return; }
        host.hidden = false;
        // (Re)build the panes when the run or the coder count changes.
        if (board.runId !== this.runId || subs.length !== this.count) {
            this.runId = board.runId; this.count = subs.length; this.offsets = {}; this.text = {}; this.bodies = {};
            var self = this;
            host.innerHTML = subs.map(function (s) {
                return '<div class="cpane" data-n="' + s.n + '">' +
                    '<div class="cpane-head"><span class="cpane-title">👷 ' + esc(s.title || ('coder ' + s.n)) + '</span>' +
                    '<span class="cpane-badge" data-n="' + s.n + '"></span>' +
                    '<button class="cpane-zoom" data-n="' + s.n + '" title="Focus this coder">⤢</button></div>' +
                    '<pre class="cpane-body" data-n="' + s.n + '"></pre></div>';
            }).join('');
            host.querySelectorAll('.cpane-body').forEach(function (el) { self.bodies[el.dataset.n] = el; });
            // Focus button (and double-click the head) enlarges one coder; click
            // again to put it back among the others.
            host.querySelectorAll('.cpane-zoom').forEach(function (btn) {
                btn.onclick = function (e) { e.stopPropagation(); self.toggleZoom(parseInt(btn.dataset.n, 10)); };
            });
            host.querySelectorAll('.cpane-head').forEach(function (h) {
                h.ondblclick = function (e) { if (e.target.classList.contains('cpane-zoom')) return; self.toggleZoom(parseInt(h.parentNode.dataset.n, 10)); };
            });
        }
        // Update state badges every sync.
        subs.forEach(function (s) {
            var badge = host.querySelector('.cpane-badge[data-n="' + s.n + '"]'); if (!badge) return;
            var map = { todo: '○ queued', doing: '● working', done: '✓ done', held: '⚠ held' };
            badge.textContent = map[s.state] || s.state || '';
            badge.className = 'cpane-badge st-' + (s.state || 'todo');
        });
        // A focused coder that vanished on rebuild reverts to the grid.
        if (this.zoomed != null && !subs.some(function (s) { return s.n === this.zoomed; }, this)) this.zoomed = null;
        this.applyZoom();
        this.poll(subs);
    },
    toggleZoom: function (n) { this.zoomed = this.zoomed === n ? null : n; this.applyZoom(); },
    applyZoom: function () {
        var host = $('#crewPanes'); if (!host) return;
        var z = this.zoomed;
        host.classList.toggle('zoomed', z != null);
        host.querySelectorAll('.cpane').forEach(function (p) {
            var isF = z != null && parseInt(p.dataset.n, 10) === z;
            p.classList.toggle('focused', isF);
            var btn = p.querySelector('.cpane-zoom');
            if (btn) { btn.textContent = isF ? '⤡' : '⤢'; btn.title = isF ? 'Restore (back to the other coders)' : 'Focus this coder'; }
        });
    },
    poll: function (subs) {
        if (!this.runId || !window.crewCoderLog) return;
        var self = this;
        subs.forEach(function (s) {
            var n = s.n, off = self.offsets[n] || 0;
            Promise.resolve(window.crewCoderLog(self.runId, n, off)).then(function (r) {
                if (!r || !r.data) return;
                self.text[n] = (self.text[n] || '') + r.data;
                self.offsets[n] = r.size;
                var el = self.bodies[n];
                if (el) { el.textContent = self.text[n]; el.scrollTop = el.scrollHeight; }
            }).catch(function () {});
        });
    }
};

// ---------- workspaces (named projects, shared with the CLI) ----------
// Always-visible project tabs over the global workspace list. Each tab is a
// project folder; clicking one opens it and restores its saved window (terminals,
// editor tabs, layout, view). Backed by window.ws* → ~/.ollamadev/workspaces.json.
var Workspaces = {
    data: { active: null, workspaces: [] },
    bind: function () {
        var self = this;
        var add = $('#wsBarAdd');
        if (add) add.onclick = function () { App.openFolderModal(false, true); };   // "+" opens a DIFFERENT project as a new tab
        // Delegated click on the strip container — attached ONCE and survives every
        // re-render, so clicking a project tab always switches (per-element handlers
        // rebuilt on each render could be missed / lost).
        var strip = $('#wsStrip');
        if (strip) strip.addEventListener('click', function (e) {
            var x = e.target.closest('.wst-x');
            if (x) { e.stopPropagation(); e.preventDefault(); self.remove(x.dataset.id); return; }
            var tab = e.target.closest('.ws-tab-item');
            if (tab && tab.dataset.id) self.switchTo(tab.dataset.id);
        });
    },
    load: function () {
        var self = this;
        if (!window.wsList) { this.render(); return Promise.resolve(this.data); }
        return Promise.resolve(window.wsList()).then(function (d) {
            self.data = (d && Array.isArray(d.workspaces)) ? { active: d.active || null, workspaces: d.workspaces } : { active: null, workspaces: [] };
            self.render();
            return self.data;
        }).catch(function () { self.render(); return self.data; });
    },
    current: function () {
        var a = this.data.active, list = this.data.workspaces || [];
        for (var i = 0; i < list.length; i++) if (list[i].id === a) return list[i];
        return null;
    },
    // Render the tabs in stable insertion order (so tabs don't jump around when
    // you switch — unlike a most-recent-first dropdown).
    render: function () {
        var strip = $('#wsStrip'); if (!strip) return;
        var self = this, act = this.data.active;
        var list = this.data.workspaces || [];
        if (!list.length) { strip.innerHTML = '<span class="ws-hint dim">No projects open — click ＋ to open one</span>'; return; }
        strip.innerHTML = list.map(function (w) {
            return '<div class="ws-tab-item' + (w.id === act ? ' active' : '') + '" data-id="' + esc(w.id) + '" title="' + esc(w.path) + '">' +
                '<span class="wst-dot"></span>' +
                '<span class="wst-name">' + esc(w.name) + '</span>' +
                '<button class="wst-x" data-id="' + esc(w.id) + '" title="Close (remove from list)">&times;</button>' +
                '</div>';
        }).join('');
        // Clicks are handled by the delegated listener in bind() — no per-element
        // handlers to (re)attach here, so a fresh render can never drop them.
    },
    switchTo: function (id) {
        if (id === this.data.active) return;
        var w = (this.data.workspaces || []).find(function (x) { return x.id === id; });
        if (w) App.openFolder(w.path, false);   // openFolder saves the outgoing ws + restores this one
    },
    addCurrent: function () {
        var self = this;
        var path = App.cwd;
        if (!path || path === '.') { banner('open a folder first', 'err'); return; }
        if (!window.wsAdd) { banner('workspaces unavailable', 'err'); return; }
        Promise.resolve(window.wsAdd(path, '')).then(function (w) {
            if (w && w.id) self.data.active = w.id;
            return self.load();
        }).then(function () { banner('workspace added', 'ok'); });
    },
    // Close a tab. If it's the active one, switch to a neighbour first so the
    // window doesn't go blank; otherwise just drop it from the list.
    remove: function (id) {
        var self = this;
        if (!window.wsRemove) return;
        var list = this.data.workspaces || [];
        var isActive = (id === this.data.active);
        var neighbour = null;
        if (isActive) {
            var i = list.findIndex(function (x) { return x.id === id; });
            var n = list[i + 1] || list[i - 1] || null;   // prefer the tab to the right
            neighbour = n ? n.id : null;
        }
        Promise.resolve(window.wsRemove(id)).then(function () {
            if (isActive && neighbour) { self.data.active = null; self.switchTo(neighbour); }
            return self.load();
        });
    },
    // Persist the current window into the active workspace (called before leaving).
    saveCurrentState: function () {
        var act = this.data.active;
        if (!act || !window.wsSaveState) return Promise.resolve();
        // Pass the window state as a JSON STRING (not a nested object) so it
        // marshals reliably across both the web bridge and the Boson FFI bridge.
        var json; try { json = JSON.stringify(App.captureState()); } catch (e) { json = '{}'; }
        return Promise.resolve(window.wsSaveState(act, json)).catch(function () {});
    }
};

// ---------- Crew roles: manage the catalog the Director assigns per subtask ----------
// Backed by the CLI (window.crewRole*), so the desktop, web, and terminal all see
// one global catalog. The Director picks a role for each subtask at plan time.
var Roles = {
    roles: [],
    bind: function () {
        var self = this;
        var open = $('#crewManageRoles'); if (open) open.onclick = function (e) { e.preventDefault(); self.open(); };
        var close = $('#rolesClose'); if (close) close.onclick = function () { self.close(); };
        var ov = $('#rolesOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.close(); };
        var save = $('#roleSave'); if (save) save.onclick = function () { self.add(); };
    },
    open: function () {
        var ov = $('#rolesOverlay'); if (!ov) return;
        // Fill the optional pinned-model dropdown from the loaded models.
        var sel = $('#roleModel'), ms = $('#modelSelect');
        if (sel && ms) {
            var opts = Array.prototype.slice.call(ms.options).map(function (o) { return o.value; }).filter(function (m) { return m !== 'shell'; });
            sel.innerHTML = '<option value="">— crew coder model —</option>' + opts.map(function (m) { return '<option>' + esc(m) + '</option>'; }).join('');
        }
        ov.hidden = false;
        this.load();
    },
    close: function () { var ov = $('#rolesOverlay'); if (ov) ov.hidden = true; },
    load: function () {
        var self = this;
        if (!window.crewRoleList) { this.render({ roles: [] }); return Promise.resolve(); }
        return Promise.resolve(window.crewRoleList()).then(function (d) { self.render(d); }).catch(function () { self.render({ roles: [] }); });
    },
    render: function (d) {
        var box = $('#rolesList'); if (!box) return;
        var self = this;
        var roles = (d && Array.isArray(d.roles)) ? d.roles : [];
        this.roles = roles;
        if (!roles.length) { box.innerHTML = '<div class="board-empty">No roles found.</div>'; return; }
        box.innerHTML = roles.map(function (r) {
            var badges = '';
            if (r.builtin) badges += '<span class="rb">built-in</span>';
            if (r.model) badges += '<span class="rb">' + esc(r.model) + '</span>';
            if (r.permission === 'readonly') badges += '<span class="rb">read-only</span>';
            var del = r.builtin ? '' : '<button class="role-del" data-role="' + esc(r.name) + '" title="Remove this role">✕</button>';
            return '<div class="role-row"><div class="role-main"><span class="role-name">' + esc(r.name) + '</span> ' + badges +
                '<div class="role-desc dim">' + esc(r.desc || 'no description') + '</div></div>' + del + '</div>';
        }).join('');
        box.querySelectorAll('.role-del').forEach(function (b) { b.onclick = function () { self.remove(b.dataset.role); }; });
    },
    add: function () {
        var self = this;
        var name = (($('#roleName') || {}).value || '').trim();
        var persona = (($('#rolePersona') || {}).value || '').trim();
        if (!name) { banner('give the role a name', 'err'); return; }
        if (!persona) { banner('a role needs a persona', 'err'); return; }
        if (!window.crewRoleAdd) { banner('roles unavailable here', 'err'); return; }
        var desc = (($('#roleDesc') || {}).value || '').trim();
        var model = (($('#roleModel') || {}).value || '').trim();
        var ro = !!($('#roleReadonly') && $('#roleReadonly').checked);
        Promise.resolve(window.crewRoleAdd(name, persona, desc, model, ro)).then(function (d) {
            self.render(d);
            ['#roleName', '#rolePersona', '#roleDesc'].forEach(function (id) { var el = $(id); if (el) el.value = ''; });
            if ($('#roleModel')) $('#roleModel').value = '';
            if ($('#roleReadonly')) $('#roleReadonly').checked = false;
            var box = $('#roleAddBox'); if (box) box.open = false;
            banner('role "' + name + '" saved', 'ok');
        }).catch(function () { banner('could not save role', 'err'); });
    },
    remove: function (name) {
        var self = this;
        if (!name || !window.crewRoleRemove) return;
        Promise.resolve(window.crewRoleRemove(name)).then(function (d) { self.render(d); banner('role "' + name + '" removed', 'ok'); }).catch(function () { banner('could not remove role', 'err'); });
    }
};

// ---------- Skills: reusable instructions the agent/crew load on demand ----------
// Backed by the CLI (window.skills*) → ~/.ollamadev/skills, one catalog for all surfaces.
var SkillMgr = {
    skills: [],
    bind: function () {
        var self = this;
        var open = $('#manageSkills'); if (open) open.onclick = function (e) { e.preventDefault(); self.open(); };
        var close = $('#skillsClose'); if (close) close.onclick = function () { self.close(); };
        var ov = $('#skillsOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.close(); };
        var save = $('#skillSave'); if (save) save.onclick = function () { self.save(); };
    },
    open: function () { var ov = $('#skillsOverlay'); if (!ov) return; ov.hidden = false; this.clearForm(); this.load(); },
    close: function () { var ov = $('#skillsOverlay'); if (ov) ov.hidden = true; },
    load: function () {
        var self = this;
        if (!window.skillsList) { this.render({ skills: [] }); return Promise.resolve(); }
        return Promise.resolve(window.skillsList()).then(function (d) { self.render(d); }).catch(function () { self.render({ skills: [] }); });
    },
    render: function (d) {
        var box = $('#skillsList'); if (!box) return;
        var self = this;
        var skills = (d && Array.isArray(d.skills)) ? d.skills : [];
        this.skills = skills;
        if (!skills.length) { box.innerHTML = '<div class="board-empty">No skills yet — create one below.</div>'; return; }
        var mine = skills.filter(function (s) { return !s.builtin; });
        var built = skills.filter(function (s) { return s.builtin; });
        var row = function (s) {
            // Built-ins are read-only starters: view/customize (saving makes a disk
            // copy that overrides), and no delete. Your own skills edit/remove freely.
            var tag = s.builtin ? '<span class="skill-tag" title="Built-in team-skill — crews load it by focus or template">built-in</span>' : '';
            var editTitle = s.builtin ? 'View / customize a copy' : 'Edit this skill';
            var del = s.builtin ? '' : '<button class="role-del" data-skill="' + esc(s.name) + '" title="Remove this skill">✕</button>';
            return '<div class="role-row"><div class="role-main"><span class="role-name">' + esc(s.name) + '</span>' + tag +
                '<div class="role-desc dim">' + esc(s.description || 'no description') + '</div></div>' +
                '<button class="role-edit" data-skill="' + esc(s.name) + '" title="' + editTitle + '">' + (s.builtin ? '⎘' : '✎') + '</button>' + del + '</div>';
        };
        var html = mine.map(row).join('');
        if (built.length) html += '<div class="skill-sec dim">Built-in team-skills (' + built.length + ') — crews load these by focus or template; customize one to override it.</div>' + built.map(row).join('');
        box.innerHTML = html;
        box.querySelectorAll('.role-edit').forEach(function (b) { b.onclick = function () { self.edit(b.dataset.skill); }; });
        box.querySelectorAll('.role-del').forEach(function (b) { b.onclick = function () { self.remove(b.dataset.skill); }; });
    },
    edit: function (name) {
        if (!window.skillsGet) return;
        var self = this;
        Promise.resolve(window.skillsGet(name)).then(function (s) {
            if (!s || s.error) { banner('could not load skill', 'err'); return; }
            if ($('#skillName')) $('#skillName').value = s.name || name;
            if ($('#skillDesc')) $('#skillDesc').value = s.description || '';
            if ($('#skillBody')) $('#skillBody').value = s.body || '';
            var box = $('#skillAddBox'); if (box) box.open = true;
            var n = $('#skillName'); if (n) n.focus();
            if (s.builtin) banner('built-in "' + (s.name || name) + '" loaded — Save creates your own editable copy that overrides it', 'ok');
        }).catch(function () { banner('could not load skill', 'err'); });
    },
    save: function () {
        var self = this;
        var name = (($('#skillName') || {}).value || '').trim();
        var desc = (($('#skillDesc') || {}).value || '').trim();
        var body = (($('#skillBody') || {}).value || '').trim();
        if (!name) { banner('give the skill a name', 'err'); return; }
        if (!body) { banner('a skill needs instructions', 'err'); return; }
        if (!window.skillsSave) { banner('skills unavailable here', 'err'); return; }
        Promise.resolve(window.skillsSave(name, desc, body)).then(function (d) {
            if (d && d.error) { banner(d.error, 'err'); return; }
            self.render(d); self.clearForm();
            var box = $('#skillAddBox'); if (box) box.open = false;
            banner('skill "' + name + '" saved', 'ok');
        }).catch(function () { banner('could not save skill', 'err'); });
    },
    remove: function (name) {
        var self = this;
        if (!name || !window.skillsRemove) return;
        Promise.resolve(window.skillsRemove(name)).then(function (d) { self.render(d); banner('skill "' + name + '" removed', 'ok'); }).catch(function () { banner('could not remove skill', 'err'); });
    },
    clearForm: function () { ['#skillName', '#skillDesc', '#skillBody'].forEach(function (id) { var el = $(id); if (el) el.value = ''; }); }
};

// ---------- Network toggles, shared with the CLI via config ----------
// 🌐 Web = the air-gap (offline) flag: all network tools (search/fetch/remote git).
// 🔍 Search = a finer switch for web search only (fetch/git unaffected).
// Both persist to config through the CLI, so terminal/desktop/web agree.
var Net = {
    on: true, search: true,
    bind: function () {
        var self = this;
        var w = $('#webToggle'); if (w) w.onclick = function () { self.toggleWeb(); };
        var s = $('#searchToggle'); if (s) s.onclick = function () { self.toggleSearch(); };
    },
    load: function () {
        var self = this, jobs = [];
        if (window.webAccess) jobs.push(Promise.resolve(window.webAccess()).then(function (on) { self.on = (on !== false); }).catch(function () {}));
        if (window.searchEnabled) jobs.push(Promise.resolve(window.searchEnabled()).then(function (on) { self.search = (on !== false); }).catch(function () {}));
        return Promise.all(jobs).then(function () { self.render(); });
    },
    toggleWeb: function () {
        var self = this;
        if (!window.setWebAccess) { banner('web toggle unavailable here', 'err'); return; }
        Promise.resolve(window.setWebAccess(!this.on)).then(function (on) {
            self.on = (on !== false); self.render();
            banner(self.on ? 'web access on' : 'air-gapped (offline) — applies to new runs', 'ok');
        }).catch(function () { banner('could not change web access', 'err'); });
    },
    toggleSearch: function () {
        var self = this;
        if (!window.setSearchEnabled) { banner('search toggle unavailable here', 'err'); return; }
        Promise.resolve(window.setSearchEnabled(!this.search)).then(function (on) {
            self.search = (on !== false); self.render();
            banner(self.search ? 'web search on' : 'web search off (fetch/git still on) — applies to new runs', 'ok');
        }).catch(function () { banner('could not change web search', 'err'); });
    },
    render: function () {
        var w = $('#webToggle');
        if (w) {
            w.textContent = this.on ? '🌐 Web' : '✈️ Offline';
            w.classList.toggle('off', !this.on);
            w.title = (this.on ? 'Web access ON — agent may search/fetch/use remote git.' : 'Air-gapped — all network tools blocked.') + ' Click to toggle (applies to new runs).';
        }
        var s = $('#searchToggle');
        if (s) {
            // When air-gapped, search is moot — show it disabled.
            s.textContent = this.search ? '🔍 Search' : '🔍 Search off';
            s.classList.toggle('off', !this.search);
            s.disabled = !this.on;
            s.title = !this.on ? 'Air-gapped — search is blocked by the Web toggle.'
                : (this.search ? 'Web search ON. Click to disable search only (fetch/git stay on).' : 'Web search OFF (fetch/git still allowed). Click to enable.');
        }
    }
};

// ---------- Browser: a localhost preview pane (vanilla iframe) ----------
// Built for previewing the dev server you're coding against (localhost:3000,
// Vite's 5173, etc.) right next to the terminals. Keeps its OWN history stack
// of bar-entered URLs — the iframe's cross-origin history isn't reachable from
// here, so back/forward walk our stack and reset the frame src. Non-localhost
// URLs are gated by the same air-gap flag as the agent's network tools (Net.on).
var Browser = {
    stack: [], idx: -1, COMMON_PORTS: [3000, 5173, 8080, 8000, 4321, 41434],
    bind: function () {
        var self = this;
        var url = $('#brUrl');
        if (url) url.addEventListener('keydown', function (e) { if (e.key === 'Enter') self.go(url.value); });
        var go = $('#brGo'); if (go) go.onclick = function () { self.go($('#brUrl').value); };
        var rl = $('#brReload'); if (rl) rl.onclick = function () { self.reload(); };
        var bk = $('#brBack'); if (bk) bk.onclick = function () { self.back(); };
        var fw = $('#brFwd'); if (fw) fw.onclick = function () { self.fwd(); };
        var ext = $('#brExt'); if (ext) ext.onclick = function () { self.openExternal(); };
        // Quick-port chips for the usual dev servers.
        var ports = $('#brPorts');
        if (ports) ports.innerHTML = this.COMMON_PORTS.map(function (p) {
            return '<button class="br-port" type="button" data-port="' + p + '" title="localhost:' + p + '">:' + p + '</button>';
        }).join('');
        if (ports) ports.addEventListener('click', function (e) {
            var b = e.target.closest('.br-port'); if (b) self.go('localhost:' + b.dataset.port);
        });
        // Proxied pages bubble link clicks up here so navigation re-proxies
        // instead of dead-ending on the next site's X-Frame-Options.
        window.addEventListener('message', function (e) {
            var d = e.data;
            if (d && typeof d.__odvNav === 'string' && /^https?:/i.test(d.__odvNav)) self.go(d.__odvNav);
        });
    },
    // Bare "3000" or "localhost:3000" → http://localhost:3000; scheme-less host → http://host.
    normalize: function (raw) {
        var s = (raw || '').trim();
        if (!s) return '';
        if (/^\d{2,5}$/.test(s)) return 'http://localhost:' + s;          // just a port
        if (/^https?:\/\//i.test(s)) return s;                             // already a URL
        if (/^localhost(:\d+)?(\/|$)/i.test(s) || /^127\.0\.0\.1/.test(s)) return 'http://' + s;
        return 'http://' + s;                                              // default to http for local-first
    },
    isLocal: function (u) {
        try {
            var h = new URL(u).hostname;
            return h === 'localhost' || h === '127.0.0.1' || h === '0.0.0.0' || h === '::1' || /\.local$/i.test(h);
        } catch (e) { return false; }
    },
    go: function (raw) {
        var u = this.normalize(raw);
        if (!u) return;
        if (!this.isLocal(u) && !Net.on) { banner('air-gapped — only localhost previews are allowed (toggle 🌐 Web to load external sites)', 'err'); return; }
        // Truncate forward history, push, and load.
        this.stack = this.stack.slice(0, this.idx + 1);
        this.stack.push(u); this.idx = this.stack.length - 1;
        this.load(u);
    },
    load: function (u) {
        var bar = $('#brUrl'); if (bar) bar.value = u;
        // Local dev servers load DIRECTLY (full fidelity — SPAs/APIs/websockets all
        // work). External sites go through the strip-proxy so X-Frame-Options can't
        // blank them; that path is best-effort.
        if (this.isLocal(u)) this.loadDirect(u);
        else this.loadProxied(u);
        this.syncNav();
    },
    loadDirect: function (u) {
        var f = $('#brFrame'), e = $('#brEmpty');
        if (e) e.hidden = true;
        if (f) { f.classList.remove('empty'); f.removeAttribute('srcdoc'); f.src = u; }
    },
    loadProxied: function (u) {
        var self = this, f = $('#brFrame'), e = $('#brEmpty');
        if (!window.proxyFetch) { this.loadDirect(u); return; }   // no bridge → best-effort direct
        if (e) { e.hidden = false; e.innerHTML = 'Loading ' + this.host(u) + '…'; }
        this._pending = u;
        Promise.resolve(window.proxyFetch(u)).then(function (r) {
            if (self._pending !== u) return;                       // superseded by a newer nav
            if (!r || !r.ok) { self.showFail(u, r && r.error); return; }
            if (e) e.hidden = true;
            if (r.direct) { if (f) { f.removeAttribute('srcdoc'); f.classList.remove('empty'); f.src = r.url || u; } }
            else if (f) { f.removeAttribute('src'); f.classList.remove('empty'); f.srcdoc = r.html || ''; }
            // Reflect post-redirect URL in the bar without pushing a new history entry.
            if (r.url && self.idx >= 0) { self.stack[self.idx] = r.url; var b = $('#brUrl'); if (b) b.value = r.url; }
        }).catch(function (err) { if (self._pending === u) self.showFail(u, String(err)); });
    },
    showFail: function (u, why) {
        var f = $('#brFrame'), e = $('#brEmpty');
        if (f) { f.classList.add('empty'); f.removeAttribute('srcdoc'); f.removeAttribute('src'); }
        if (e) {
            e.hidden = false;
            e.innerHTML = "Couldn't embed <b>" + this.host(u) + '</b>' + (why ? ' (' + esc(String(why)) + ')' : '') +
                '. <button id="brFailExt" class="br-port" type="button" style="margin-left:6px">↗ Open in real browser</button>';
            var btn = document.getElementById('brFailExt');
            var self = this;
            if (btn) btn.onclick = function () { self.openExternalUrl(u); };
        }
    },
    host: function (u) { try { return new URL(u).host; } catch (e) { return u; } },
    reload: function () { if (this.idx >= 0) this.load(this.stack[this.idx]); },
    back: function () { if (this.idx > 0) { this.idx--; this.load(this.stack[this.idx]); } },
    fwd: function () { if (this.idx < this.stack.length - 1) { this.idx++; this.load(this.stack[this.idx]); } },
    syncNav: function () {
        var bk = $('#brBack'), fw = $('#brFwd');
        if (bk) bk.disabled = this.idx <= 0;
        if (fw) fw.disabled = this.idx >= this.stack.length - 1;
    },
    openExternal: function () {
        var u = this.idx >= 0 ? this.stack[this.idx] : this.normalize($('#brUrl').value);
        this.openExternalUrl(u);
    },
    openExternalUrl: function (u) {
        if (!u) { banner('nothing to open', 'err'); return; }
        if (window.openExternal) { Promise.resolve(window.openExternal(u)).catch(function () { window.open(u, '_blank'); }); }
        else window.open(u, '_blank');
    },
    onShow: function () { var b = $('#brUrl'); if (b && this.idx < 0) b.focus(); }
};

// ---------- Semantic code search (sidebar panel, backed by the local index) ----------
var CodeSearch = {
    bind: function () {
        var self = this;
        var inp = $('#codeSearchInput');
        if (inp) inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') self.run(inp.value); });
    },
    onShow: function () {
        var inp = $('#codeSearchInput'); if (inp) inp.focus();
        var hint = $('#codeSearchHint');
        if (!window.codeIndexStatus || !hint) return;
        Promise.resolve(window.codeIndexStatus()).then(function (st) {
            if (!st || !st.exists) {
                hint.innerHTML = 'No index for this project yet. <button id="csBuild" class="linklike">Build it</button> — embeds the repo locally.';
                var b = $('#csBuild'); if (b) b.onclick = function () { CodeSearch.build(); };
            } else {
                hint.textContent = st.chunks + ' chunks · ' + st.files + ' files · ' + (st.model || '');
            }
        }).catch(function () {});
    },
    build: function () {
        var hint = $('#codeSearchHint'); if (hint) hint.textContent = 'Building index… this can take a moment.';
        if (!window.codeIndexBuild) return;
        Promise.resolve(window.codeIndexBuild()).then(function (st) {
            if (!hint) return;
            hint.textContent = (st && st.exists)
                ? (st.chunks + ' chunks · ' + st.files + ' files · ' + (st.model || ''))
                : 'Build failed — is the embedding model installed? (ollama pull nomic-embed-text)';
        }).catch(function () { if (hint) hint.textContent = 'Index build failed.'; });
    },
    run: function (q) {
        q = (q || '').trim(); if (!q) return;
        var box = $('#codeSearchResults'); if (!box) return;
        if (!window.codeSearch) { box.innerHTML = '<div class="board-empty">search unavailable here</div>'; return; }
        box.innerHTML = '<div class="dim">searching…</div>';
        Promise.resolve(window.codeSearch(q, 12)).then(function (r) {
            if (!r || r.error) {
                box.innerHTML = '<div class="board-empty">' + (r && r.error === 'no_index' ? 'No index yet — build it above.' : 'no results') + '</div>';
                return;
            }
            var res = r.results || [];
            if (!res.length) { box.innerHTML = '<div class="board-empty">no matches</div>'; return; }
            box.innerHTML = res.map(function (m) {
                return '<div class="cs-hit" data-file="' + esc(m.file) + '">' +
                    '<div class="cs-file">' + esc(m.file) + '<span class="cs-loc">:' + m.start + '-' + m.end + '</span><span class="cs-score">' + m.score + '</span></div>' +
                    '<div class="cs-snip dim">' + esc(String(m.snippet || '').replace(/\s+/g, ' ').slice(0, 160)) + '</div></div>';
            }).join('');
            box.querySelectorAll('.cs-hit').forEach(function (el) {
                el.onclick = function () {
                    var f = el.dataset.file; if (!f) return;
                    var abs = (App.cwd && App.cwd !== '.') ? App.cwd.replace(/\/$/, '') + '/' + f : f;
                    App.setLayout('split'); Editor.open(abs, f.split('/').pop());
                };
            });
        }).catch(function () { box.innerHTML = '<div class="board-empty">search failed</div>'; });
    }
};

// Diff — read-only review of the agent's working-tree changes. Shows a
// colorized git diff; it never applies or commits (git stays agent-driven).
var Diff = {
    bind: function () {
        var self = this;
        var b = $('#diffBtn'); if (b) b.onclick = function () { self.open(); };
        var c = $('#diffClose'); if (c) c.onclick = function () { self.close(); };
        var r = $('#diffRefresh'); if (r) r.onclick = function () { self.load(); };
        var ov = $('#diffOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.close(); };
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { var o = $('#diffOverlay'); if (o && !o.hidden) self.close(); }
        });
    },
    open: function () { var o = $('#diffOverlay'); if (o) { o.hidden = false; this.load(); } },
    close: function () { var o = $('#diffOverlay'); if (o) o.hidden = true; },
    load: function () {
        var body = $('#diffBody'), files = $('#diffFiles');
        if (!body) return;
        if (!window.reviewDiff) { body.innerHTML = '<div class="diff-empty dim">diff unavailable here</div>'; if (files) files.innerHTML = ''; return; }
        body.innerHTML = '<div class="diff-empty dim">Loading…</div>'; if (files) files.innerHTML = '';
        Promise.resolve(window.reviewDiff()).then(function (r) {
            if (!r || !r.repo) { body.innerHTML = '<div class="diff-empty dim">Not a git repository.</div>'; return; }
            var text = (r.diff || '').trim();
            if (!text) { body.innerHTML = '<div class="diff-empty dim">No working-tree changes. Clean tree ✓</div>'; return; }
            Diff.render(text);
        }).catch(function () { body.innerHTML = '<div class="diff-empty dim">Could not load the diff.</div>'; });
    },
    // Parse a unified diff into per-file blocks and colorize each line. Vanilla.
    render: function (text) {
        var body = $('#diffBody'), filesEl = $('#diffFiles');
        var lines = text.split('\n');
        var blocks = [], cur = null, add = 0, del = 0;
        function push() { if (cur) { cur.add = add; cur.del = del; blocks.push(cur); } }
        for (var i = 0; i < lines.length; i++) {
            var ln = lines[i];
            if (ln.indexOf('diff --git ') === 0) {
                push(); add = 0; del = 0;
                var m = ln.match(/ b\/(.+)$/) || ln.match(/ a\/(.+?) /);
                cur = { name: m ? m[1] : ln.slice(11), rows: [] };
                continue;
            }
            if (!cur) continue;
            if (ln.indexOf('+++ ') === 0 || ln.indexOf('--- ') === 0 || ln.indexOf('index ') === 0 ||
                ln.indexOf('new file') === 0 || ln.indexOf('deleted file') === 0 || ln.indexOf('similarity ') === 0 ||
                ln.indexOf('rename ') === 0 || ln.indexOf('old mode') === 0 || ln.indexOf('new mode') === 0) continue;
            var cls = 'd-ctx';
            if (ln.indexOf('@@') === 0) cls = 'd-hunk';
            else if (ln.charAt(0) === '+') { cls = 'd-add'; add++; }
            else if (ln.charAt(0) === '-') { cls = 'd-del'; del++; }
            cur.rows.push('<div class="' + cls + '">' + (esc(ln) || '&nbsp;') + '</div>');
        }
        push();
        if (!blocks.length) { body.innerHTML = '<div class="diff-empty dim">No file changes parsed.</div>'; return; }
        // File summary chips that scroll to the block.
        if (filesEl) {
            filesEl.innerHTML = blocks.map(function (b, idx) {
                return '<button class="diff-chip" data-i="' + idx + '">' + esc(b.name) +
                    '<span class="d-stat"><span class="d-plus">+' + b.add + '</span> <span class="d-minus">-' + b.del + '</span></span></button>';
            }).join('');
        }
        body.innerHTML = blocks.map(function (b, idx) {
            return '<div class="diff-file" id="dfile-' + idx + '"><div class="diff-fname">' + esc(b.name) + '</div>' +
                '<div class="diff-code">' + b.rows.join('') + '</div></div>';
        }).join('');
        if (filesEl) filesEl.querySelectorAll('.diff-chip').forEach(function (c) {
            c.onclick = function () { var t = $('#dfile-' + c.dataset.i); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
        });
    }
};

// Temperature dropdown — reads/writes ollama.temperature via the CLI config.
// Lower = more deterministic tool-calling; higher = more creative. Applies to new runs.
var Temp = {
    bind: function () {
        var sel = $('#tempSelect'); if (!sel) return;
        if (window.temperature) {
            Promise.resolve(window.temperature()).then(function (v) {
                v = String(v == null ? '0.3' : v);
                // Select the closest preset option to the stored value.
                var match = Array.prototype.slice.call(sel.options).filter(function (o) { return o.value === v; })[0];
                if (match) sel.value = v;
                else if (sel.querySelector('option[value="0.3"]')) sel.value = '0.3';
            }).catch(function () {});
        }
        sel.addEventListener('change', function () {
            if (window.setTemperature) Promise.resolve(window.setTemperature(sel.value)).catch(function () {});
            if (window.banner) banner('temperature → ' + sel.value, 'ok');
        });
    }
};

// Voice (STT) settings — model picker + transcription history. Reads/writes the
// shared stt.model via the CLI (window.sttModel/setSttModel), so CLI, desktop and
// web stay in lockstep. Both controls reveal only when a local STT engine exists.
var Stt = {
    bind: function () {
        var sel = $('#sttSelect'), histBtn = $('#sttHistoryBtn');
        if (!window.sttEnabled) return;
        Promise.resolve(window.sttEnabled()).then(function (on) {
            if (!on) return;
            if (sel) {
                sel.hidden = false;
                if (window.sttModel) Promise.resolve(window.sttModel()).then(function (m) {
                    m = String(m || 'base');
                    if (Array.prototype.slice.call(sel.options).some(function (o) { return o.value === m; })) sel.value = m;
                }).catch(function () {});
                sel.addEventListener('change', function () {
                    if (window.setSttModel) Promise.resolve(window.setSttModel(sel.value)).catch(function () {});
                    banner('voice model → ' + sel.value, 'ok');
                });
            }
            if (histBtn) { histBtn.hidden = false; histBtn.onclick = function () { Stt.openHistory(); }; }
        }).catch(function () {});
        var cl = $('#voiceHistClose'), cx = $('#voiceHistClear'), ov = $('#voiceOverlay');
        if (cl) cl.onclick = function () { Stt.closeHistory(); };
        if (ov) ov.onclick = function (e) { if (e.target === ov) Stt.closeHistory(); };
        if (cx) cx.onclick = function () {
            if (window.sttClearHistory) Promise.resolve(window.sttClearHistory()).then(function () { Stt.renderHistory([]); banner('voice history cleared', 'ok'); }).catch(function () {});
        };
    },
    openHistory: function () {
        var ov = $('#voiceOverlay'); if (!ov) return;
        ov.hidden = false;
        var list = $('#voiceHistoryList'); if (list) list.textContent = 'loading…';
        if (window.sttHistory) Promise.resolve(window.sttHistory(50)).then(function (rows) {
            Stt.renderHistory(Array.isArray(rows) ? rows : []);
        }).catch(function () { Stt.renderHistory([]); });
    },
    closeHistory: function () { var ov = $('#voiceOverlay'); if (ov) ov.hidden = true; },
    renderHistory: function (rows) {
        var list = $('#voiceHistoryList'); if (!list) return;
        if (!rows.length) { list.innerHTML = '<div class="dim" style="padding:12px 0;">No voice history yet — click the 🎤 mic and speak.</div>'; return; }
        list.innerHTML = '';
        rows.slice().reverse().forEach(function (r) {
            var row = document.createElement('div'); row.className = 'vh-row';
            var when = r.ts ? new Date(r.ts * 1000).toLocaleString() : '';
            var meta = document.createElement('div'); meta.className = 'vh-meta dim';
            meta.textContent = when + (r.model ? '  ·  ' + r.model : '');
            var txt = document.createElement('div'); txt.className = 'vh-text';
            txt.textContent = r.text || '';
            row.appendChild(meta); row.appendChild(txt); list.appendChild(row);
        });
    }
};

var App = {
    terminals: [], cwd: '.', layout: 'split', termLayout: 'free', zoomed: null, view: 'code', panel: 'files', crewBoard: null, crewPoll: null,
    // PTYs spawned this session, by id. Survives detach (workspace switch) so we
    // can RE-attach a still-running terminal; gone after a restart → respawn fresh.
    live: {},
    init: function () {
        var self = this;
        this.initThemes();
        this.startAutosave();   // persist the active workspace so it resumes on reopen
        $('#newTermBtn').onclick = function () { self.newTerminal(); };
        var ta = $('#termArrange'); if (ta) ta.onclick = function () { self.setTermLayout(self.termLayout === 'free' ? 'tiled' : 'free'); };
        // Layout mode is a global preference — reopen in whichever mode you last used.
        // Free is the default; only an explicit 'tiled' choice opts back into the grid.
        try { self.termLayout = localStorage.getItem('ade.termLayout') === 'tiled' ? 'tiled' : 'free'; } catch (e) {}
        if (ta) ta.textContent = self.termLayout === 'free' ? '⮻ Free' : '⊞ Tiled';
        $('#layoutBtn').onclick = function () { self.cycleLayout(); };
        // Open-folder modal
        var ofb = $('#openFolderBtn'); if (ofb) ofb.onclick = function () { self.openFolderModal(false); };
        var fok = $('#folderOpen'); if (fok) fok.onclick = function () { self.submitFolder(); };
        var fcx = $('#folderCancel'); if (fcx) fcx.onclick = function () { self.closeFolder(); };
        var fov = $('#folderOverlay'); if (fov) fov.onclick = function (e) { if (e.target === fov) self.closeFolder(); };
        var fpi = $('#folderPath'); if (fpi) fpi.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') self.submitFolder();
            else if (e.key === 'Escape') self.closeFolder();
        });
        this.initResponsive();
        // Voice dictation for the Crew task box (shown only if a local STT engine is configured).
        Voice.init('crewMic', 'crewTask');
        // Crew — single screen: type the task, Run. (Advanced section is optional.)
        var fb = $('#crewBtn'); if (fb) fb.onclick = function () { self.openCrew(); };
        var fc = $('#crewCancel'); if (fc) fc.onclick = function () { self.closeCrew(); };
        var fr = $('#crewRun'); if (fr) fr.onclick = function () { self.submitCrew(); };
        var ov = $('#modalOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.closeCrew(); };
        var ft = $('#crewTask'); if (ft) ft.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') self.closeCrew();
            else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') self.submitCrew();
        });
        var cps = $('#crewPreset'); if (cps) cps.onchange = function () { if (cps.value) self.applyPreset(cps.value); };
        var cpsv = $('#crewPresetSave'); if (cpsv) cpsv.onclick = function () { self.savePreset(); };
        var cpdl = $('#crewPresetDel'); if (cpdl) cpdl.onclick = function () { self.delPreset(); };
        // Activity rail: switch the sidebar between Files and Tasks.
        document.querySelectorAll('.rail-btn').forEach(function (b) {
            b.onclick = function () { self.setPanel(b.dataset.panel); };
        });
        // Workspace view tabs: Workspace (code) vs Board (kanban) vs Graph (memory).
        document.querySelectorAll('.ws-tab').forEach(function (t) {
            t.onclick = function () { self.setView(t.dataset.view); };
        });
        Graph.bind();
        Workspaces.bind();
        Roles.bind();
        SkillMgr.bind();
        Net.bind(); Net.load();
        Browser.bind();
        CodeSearch.bind();
        Diff.bind();
        Temp.bind();
        Stt.bind();
        // Add a task from the sidebar.
        var ti = $('#taskInput');
        if (ti) ti.addEventListener('keydown', function (e) { if (e.key === 'Enter') { Tasks.add(ti.value); ti.value = ''; } });
        this.initSplitter();
        // Global Ctrl/Cmd+S saves the active editor tab from anywhere.
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); Editor.save(); }
        });
        Tasks.load(); Tasks.render();
        this.setLayout('term'); // CLI/terminal is the focus by default; editor on demand
        Promise.resolve(window.cliPath ? window.cliPath() : 'ollamadev').then(function (p) { self.cli = p || 'ollamadev'; }).catch(function () { self.cli = 'ollamadev'; });
        Promise.resolve(window.homeDir ? window.homeDir() : '').then(function (h) { window.HOME_DIR = h || ''; }).catch(function () {});
        this.loadModels().then(function () {
            banner('ready', 'ok');
            // Startup: reopen the active workspace (restoring its window). With no
            // workspaces yet, prompt for a folder — it becomes the first workspace.
            return Workspaces.load();
        }).then(function () {
            var cur = Workspaces.current();
            if (cur) { self.openFolder(cur.path, true); return; }
            var last = ''; try { last = localStorage.getItem('ade.folder') || ''; } catch (e) {}
            self.pendingFolder = last;
            self.openFolderModal(true);
        });
    },
    cdPrefix: function (cwd) {
        cwd = cwd || this.cwd;
        return (cwd && cwd !== '.') ? "cd '" + cwd.replace(/'/g, "'\\''") + "' && " : '';
    },
    // Expand a leading ~ to an absolute path so a typed folder works as both the pty
    // cwd (is_dir check) and the shell `cd` prefix (which doesn't expand quoted ~).
    expandHome: function (p) {
        p = (p || '').trim();
        if (p === '~') return window.HOME_DIR || p;
        if (p.indexOf('~/') === 0 && window.HOME_DIR) return window.HOME_DIR + p.slice(1);
        return p;
    },
    // blank=true opens it as "add another project" — empty path, so you don't
    // accidentally re-open the current folder (which would just no-op).
    openFolderModal: function (firstRun, blank) {
        var o = $('#folderOverlay'); if (!o) return;
        this._folderFirstRun = !!firstRun;
        var inp = $('#folderPath');
        if (inp) {
            var pre = blank ? '' : (this.pendingFolder || this.cwd || '');
            inp.value = (pre && pre !== '.') ? pre : '';
            this.pendingFolder = '';
        }
        var sub = o.querySelector('.modal-sub');
        if (sub) sub.textContent = blank
            ? 'Opens it as a new project tab — your current one stays open in its own tab.'
            : 'Points the file tree, terminals, and Crew at this folder.';
        o.hidden = false; if (inp) { inp.focus(); inp.select(); }
    },
    closeFolder: function () {
        var o = $('#folderOverlay'); if (o) o.hidden = true;
        // First run with no folder chosen → fall back to the default root so the app isn't empty.
        if (this._folderFirstRun && !this.terminals.length) {
            this._folderFirstRun = false;
            var self = this;
            Promise.resolve(window.getRoot ? window.getRoot() : '.').then(function (r) { self.loadFiles(r || '.'); self.newTerminal(); }).catch(function () { self.loadFiles('.'); self.newTerminal(); });
        }
    },
    submitFolder: function () {
        var path = ($('#folderPath').value || '').trim();
        if (!path) { $('#folderPath').focus(); return; }
        this.openFolder(path, this._folderFirstRun);
    },
    // Open a folder as the active workspace: validate it, save the workspace we're
    // leaving, register/look-up this one, then restore its window (terminals,
    // editor tabs, layout, view). Used by 📂 Open, the ＋, AND tab switching.
    // Every workspace-bookkeeping step is best-effort: if any binding hiccups
    // (e.g. a marshalling quirk), the folder still opens and the tabs still
    // refresh — a workspace add must never silently "do nothing".
    openFolder: function (path, firstRun) {
        var self = this;
        Promise.resolve(window.setRoot ? window.setRoot(path) : { root: path }).then(function (r) {
            // Only an EXPLICIT error aborts. Some bridges (e.g. Boson FFI) resolve a
            // binding to undefined/empty even on success — in that case fall back to
            // the requested path so a project switch never silently does nothing.
            if (r && r.error) { banner('open failed: ' + r.error, 'err'); var fp = $('#folderPath'); if (fp) fp.focus(); return; }
            var root = (r && r.root) ? r.root : path;
            // Land on the folder immediately — this part can't fail on bindings.
            var finish = function (w) {
                self.cwd = root;
                try { localStorage.setItem('ade.folder', root); } catch (e) {}
                var o = $('#folderOverlay'); if (o) o.hidden = true;
                self._folderFirstRun = false;
                self.loadFiles(root);
                if (w && w.id) Workspaces.data.active = w.id;
                self.restoreState(w ? w.state : null);   // empty/absent state → spawns one fresh terminal
                Workspaces.load();                        // refresh the tab strip (re-reads the list)
                banner('opened ' + root, 'ok');
            };
            // Save the workspace we're leaving — but do NOT gate the switch on it.
            // captureState() runs synchronously inside saveCurrentState(), so the
            // snapshot is taken now (before we detach below); the persist binding
            // then finishes in the background. Gating here meant a hung save
            // binding made every switch AFTER the first silently do nothing
            // ("switching only works once"), since the first switch has no prior
            // workspace to save.
            if (Workspaces.data.active && self.cwd && self.cwd !== '.') {
                try { Workspaces.saveCurrentState(); } catch (e) {}
            }
            if (!firstRun) self.detachTerminals();   // keep ptys alive for re-attach
            Promise.resolve(window.wsAdd ? window.wsAdd(root, '') : null)
                .catch(function () { return null; })
                .then(finish).catch(function () { finish(null); });   // even if bookkeeping fails, show the folder
        }).catch(function (e) { banner('open error: ' + e, 'err'); });
    },
    THEMES: [['void', 'Void'], ['neon-tokyo', 'Neon Tokyo'], ['synthwave', 'Synthwave'], ['dracula', 'Dracula'], ['midnight', 'Midnight'], ['mono', 'Mono']],
    initThemes: function () {
        var sel = $('#themeSelect'); if (!sel) return;
        sel.innerHTML = this.THEMES.map(function (t) { return '<option value="' + t[0] + '">' + t[1] + '</option>'; }).join('');
        var saved = 'void';
        try { saved = localStorage.getItem('ade.theme') || 'void'; } catch (e) {}
        this.applyTheme(saved); sel.value = saved;
        sel.onchange = function () { App.applyTheme(sel.value); };
    },
    applyTheme: function (t) {
        document.body.dataset.theme = t;
        try { localStorage.setItem('ade.theme', t); } catch (e) {}
    },
    setPanel: function (p) {
        this.panel = p;
        document.querySelectorAll('.rail-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.panel === p); });
        $('#filesPanel').hidden = p !== 'files';
        $('#tasksPanel').hidden = p !== 'tasks';
        var sp = $('#searchPanel'); if (sp) sp.hidden = p !== 'search';
        if (p === 'search') CodeSearch.onShow();
    },
    setView: function (v) {
        this.view = v;
        document.querySelectorAll('.ws-tab').forEach(function (t) { t.classList.toggle('active', t.dataset.view === v); });
        $('#codeView').hidden = v !== 'code';
        $('#boardView').hidden = v !== 'board';
        $('#graphView').hidden = v !== 'graph';
        $('#browserView').hidden = v !== 'browser';
        if (v === 'board') { this.startCrewPoll(); Tasks.render(); CrewPanes.sync(this.crewBoard); }
        if (v === 'graph') Graph.load();
        if (v === 'browser') Browser.onShow();
    },
    // Hand a task title to an agent terminal's CLI (create one if none exist).
    runTaskInAgent: function (title) {
        var self = this;
        var type = function (t, delay) {
            t.setStatus('running'); t.lastData = Date.now();
            setTimeout(function () { try { window.termWrite(t.id, strToB64(title + '\n')); } catch (e) {} }, delay || 0);
            self.setView('code'); banner('running task in ' + t.id.slice(-6), 'ok');
        };
        if (this.terminals.length) { type(this.terminals[0], 0); }
        else {
            var model = this.realModel();   // a task needs an agent, never the shell entry
            var id = rid(); var t = new Terminal(id, model);
            Promise.resolve(window.termCreate(id, model)).then(function () {
                self.terminals.push(t); self.render(); self.launchCli(id, model);
                type(t, 1800); // wait for the CLI to boot before sending the prompt
            });
        }
    },
    setLayout: function (name) {
        this.layout = name;
        var cv = $('#codeView');
        if (cv) cv.className = 'ws-view ' + (name === 'term' ? 'focus-term' : name === 'editor' ? 'focus-editor' : '');
        // Drag-sizing only applies in split; clear it so the focus modes fill fully.
        if (name !== 'split') { var ep = $('#editorPane'), tm = $('#terminals'); if (ep) ep.style.flex = ''; if (tm) tm.style.flex = ''; }
        var btn = $('#layoutBtn');
        if (btn) btn.textContent = name === 'term' ? 'Terminals' : name === 'editor' ? 'Editor' : 'Split';
        this.render();
    },
    // Draggable divider: in split mode, drag to give terminals more/less height.
    initSplitter: function () {
        var split = $('#vsplit'), cv = $('#codeView'), ep = $('#editorPane'), tm = $('#terminals');
        if (!split || !cv || !ep || !tm) return;
        var dragging = false;
        var self = this;
        split.addEventListener('mousedown', function (e) { if (self.layout !== 'split') return; dragging = true; document.body.style.userSelect = 'none'; e.preventDefault(); });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var r = cv.getBoundingClientRect();
            var top = e.clientY - r.top - 4;            // editor height under the cursor
            top = Math.max(60, Math.min(r.height - 90, top)); // keep both panes usable
            ep.style.flex = '0 0 ' + top + 'px';
            tm.style.flex = '1 1 0';
        });
        document.addEventListener('mouseup', function () { if (dragging) { dragging = false; document.body.style.userSelect = ''; } });
    },
    cycleLayout: function () {
        this.setLayout(this.layout === 'split' ? 'term' : this.layout === 'term' ? 'editor' : 'split');
    },
    // Recommended fast coder models, in preference order, for the crew run.
    CREW_PREFERRED: ['qwen2.5-coder', 'qwen3-coder', 'codestral', 'deepseek-coder', 'mistral', 'llama3.1'],
    // Setup templates (BridgeSpace-style) — prefill the task + suggested config.
    // Each template carries the built-in team-skill(s) the crew should load for that
    // kind of work — forced in via `--skill` regardless of focus, so e.g. picking
    // Tests always loads testing-discipline. (Domain teams add more by focus.)
    CREW_TEMPLATES: [
        { id: 'feature', label: '✨ Feature', task: 'Implement this feature: ', max: 3, review: true, skills: ['testing-discipline'] },
        { id: 'bugfix', label: '🐛 Bug fix', task: 'Find and fix this bug: ', max: 1, review: true, skills: ['testing-discipline'] },
        { id: 'tests', label: '🧪 Tests', task: 'Write thorough tests for: ', max: 2, review: true, skills: ['testing-discipline'] },
        { id: 'refactor', label: '♻️ Refactor', task: 'Refactor for clarity/maintainability (no behavior change): ', max: 2, review: true, skills: ['refactor-safety'] },
        { id: 'docs', label: '📝 Docs', task: 'Write/update documentation for: ', max: 1, review: false, skills: ['docs-writing'] },
        { id: 'audit', label: '🔍 Audit & fix', task: 'Review the codebase for bugs and security issues, and fix them: ', max: 2, review: true, skills: ['security-hardening'] },
        { id: 'blank', label: '➕ Blank', task: '', max: 2, review: true, skills: [] }
    ],
    openCrew: function () {
        var o = $('#modalOverlay'); if (!o) return;
        // Step 1 default folder = the open project.
        var cf = $('#crewFolder'); if (cf) cf.value = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        // Per-role model pickers default to your CONFIG (crew.coderModel etc.) so
        // whatever you set sticks; falls back to a recommended model if unset.
        var opts = Array.prototype.slice.call($('#modelSelect').options).map(function (o) { return o.value; }).filter(function (m) { return m !== 'shell'; });
        var fallback = opts[0] || '';
        for (var i = 0; i < this.CREW_PREFERRED.length; i++) {
            var hit = opts.find(function (m) { return m.indexOf(this.CREW_PREFERRED[i]) !== -1; }, this);
            if (hit) { fallback = hit; break; }
        }
        var apply = function (cfg) {
            cfg = cfg || {};
            var byRole = { crewModelDirector: cfg.directorModel, crewModelResearcher: cfg.researcherModel, crewModelCoder: cfg.coderModel, crewModelAuditor: cfg.auditorModel };
            Object.keys(byRole).forEach(function (id) {
                var sel = $('#' + id); if (!sel) return;
                var want = byRole[id] || fallback;
                var list = (want && opts.indexOf(want) === -1) ? [want].concat(opts) : opts;   // ensure the configured model is selectable
                if (!list.length) return;
                sel.innerHTML = list.map(function (m) { return '<option' + (m === want ? ' selected' : '') + '>' + esc(m) + '</option>'; }).join('');
            });
        };
        Promise.resolve(window.crewModels ? window.crewModels() : {}).then(apply).catch(function () { apply({}); });
        this.crewFocus = "";
        this.crewTemplateSkills = [];

        this.renderTemplates();
        this.populatePresets();
        var pl = $('#crewProjLine'); if (pl) pl.textContent = 'Runs in: ' + (this.cwd || '.') + ' · default team: Director + Researcher + 2 Coders + Auditor · review on';
        var adv = document.querySelector('.crew-adv'); if (adv) adv.open = false;
        o.hidden = false;
        var t = $('#crewTask'); if (t) { t.focus(); }
    },
    closeCrew: function () { var o = $('#modalOverlay'); if (o) o.hidden = true; },
    renderTemplates: function () {
        var box = $('#crewTemplates'); if (!box) return;
        var self = this;
        box.innerHTML = this.CREW_TEMPLATES.map(function (t) { return '<span class="wiz-tpl" data-id="' + t.id + '">' + t.label + '</span>'; }).join('');
        box.querySelectorAll('.wiz-tpl').forEach(function (el) {
            el.onclick = function () {
                var t = self.CREW_TEMPLATES.find(function (x) { return x.id === el.dataset.id; });
                if (!t) return;
                box.querySelectorAll('.wiz-tpl').forEach(function (e) { e.classList.remove('active'); });
                el.classList.add('active');
                $('#crewTask').value = t.task;
                $('#crewMax').value = String(t.max);
                if ($('#crewReview')) $('#crewReview').checked = t.review;
                self.crewTemplateSkills = t.skills || [];   // forced into the run via --skill
                var ta = $('#crewTask'); ta.focus(); ta.selectionStart = ta.selectionEnd = ta.value.length;
            };
        });
    },
    mval: function (id) { var s = $('#' + id); return (s && s.value) || this.realModel(); },
    setMval: function (id, v) { var s = $('#' + id); if (!s || !v) return; if (![].some.call(s.options, function (o) { return o.value === v; })) s.innerHTML += '<option>' + esc(v) + '</option>'; s.value = v; },
    // Built-in specialized teams — by software domain (with a focus the agents
    // follow) and by task type. Each sets the crew composition.
    CREW_TEAMS: [
        // --- by kind of software you're building (D() = a domain team) ---
        D('🌐 Website', 'A website (static / marketing / content) — plain HTML/CSS/JS or the site generator (Next/Astro/Hugo/Eleventy/WordPress). Prioritize semantic markup, responsive design, SEO, fast load, and accessibility.', 3),
        D('🚀 Landing Page', 'A single high-converting landing page. Prioritize clear hero/CTA, responsive layout, fast load, SEO/meta, and a working contact/signup form.', 2),
        D('🖥 Web App', 'A web application (SPA or full-stack) — the project\'s framework (React/Vue/Svelte/Angular + backend). Prioritize components, state, routing, API integration, auth, and tests.', 3),
        D('☁️ SaaS Product', 'A SaaS product — multi-tenant app with auth, billing/subscriptions, dashboards. Prioritize tenant isolation, security, and reliable billing logic.', 3),
        D('🛒 E-commerce', 'An e-commerce site/app — catalog, cart, checkout, payments, orders. Prioritize money/tax math correctness, payment security, and inventory integrity.', 3),
        D('📊 Admin Dashboard', 'An admin dashboard / internal tool — tables, forms, charts, CRUD, role-based access. Prioritize data accuracy, pagination, and clear UX.', 2),
        D('📰 Blog / CMS', 'A blog or CMS — content models, editor, rendering, SEO, RSS. Prioritize content structure and safe rendering of user content.', 2),
        D('📚 Docs Site', 'A documentation site (Docusaurus/MkDocs/Astro). Prioritize navigation, search, code samples, and clear structure.', 2),
        D('💬 Forum / Community', 'A forum/community app — threads, posts, users, moderation. Prioritize data integrity, spam/abuse handling, and performance at scale.', 2),
        D('📱 PWA', 'A Progressive Web App — installable, offline-capable. Mind the service worker, manifest, caching strategy, and responsive UI.', 2),
        D('📱 Mobile App', 'A mobile app — the project\'s platform (iOS/Android/React Native/Flutter). Mind lifecycle, state, navigation, and platform UX guidelines.', 2),
        D('🖥 Desktop App', 'A desktop app — the project\'s toolkit (Electron/Tauri/Qt/GTK). Mind windowing, packaging, and OS integration.', 2),
        D('🔌 REST API / Backend', 'A REST API / backend service — the project\'s language & framework. Prioritize routing, validation, error handling, auth, and tests.', 3),
        D('🔗 GraphQL API', 'A GraphQL API — schema, resolvers, N+1 avoidance, auth, and pagination. Keep the schema clean and typed.', 2),
        D('⚡ Realtime / WebSocket', 'A realtime service (WebSocket/SSE) — connection lifecycle, rooms/channels, backpressure, and reconnection.', 2),
        D('λ Serverless / Functions', 'Serverless functions (Lambda/Cloud Functions/Workers). Mind cold starts, statelessness, env config, and least-privilege IAM.', 2),
        D('🧩 Microservice', 'A microservice — single responsibility, clear API contract, health checks, observability, and resilient inter-service calls.', 2),
        D('🗄 Database / Schema', 'Database work — schema, migrations, queries. Mind indexing, integrity constraints, and safe/reversible migrations.', 2),
        D('🔢 Data Pipeline / ETL', 'A data pipeline / ETL — extract, transform, load. Prioritize idempotency, schema validation, and recovery from partial failures.', 2),
        D('📊 Data / ML', 'A data/ML project (Python: pandas/numpy/scikit/torch). Prioritize reproducibility, data validation, and clear runnable scripts/notebooks.', 2),
        D('🧠 AI / LLM App', 'An AI/LLM app — prompts, agent loops, API/SDK usage, streaming, token limits, and caching.', 2),
        D('🎮 Game', 'A game — the project\'s engine (Unity/Godot/Phaser/etc.). Mind the game loop, performance, input, and assets.', 2),
        D('🧰 CLI Tool', 'A command-line tool — clear args/flags, helpful output, correct exit codes, and tests.', 2),
        D('📦 Library / SDK', 'A reusable library/package/SDK — clean public API, docs, semantic versioning, no leaked internals, and tests.', 2),
        D('🧩 Browser Extension', 'A browser extension (Chrome/Firefox, Manifest V3). Mind the manifest, content/background scripts, least-privilege permissions, and messaging.', 2),
        D('🧷 VS Code Extension', 'A VS Code extension — contribution points, activation events, commands, and the extension API. Keep activation lean.', 2),
        D('🔌 Plugin', 'A plugin (WordPress/Figma/Obsidian/etc.) — follow the host\'s plugin API, hooks/lifecycle, and packaging.', 2),
        D('🤖 Bot', 'A chat bot (Discord/Slack/Telegram). Mind the platform SDK, event/command handlers, rate limits, and token security.', 2),
        D('🤖 Automation / Script', 'An automation script — robust I/O, error handling, logging, idempotency, and safe handling of credentials.', 1),
        D('⚙️ DevOps / Infra', 'DevOps/infra (IaC, Docker, CI/CD). Prioritize idempotency, safety, least privilege, and clear config. Never hard-code secrets.', 2),
        D('🔄 CI/CD Pipeline', 'A CI/CD pipeline — build, test, deploy stages. Prioritize caching, fail-fast, secrets handling, and reproducibility.', 2),
        D('🔧 Embedded / IoT', 'Embedded/IoT/firmware (C/C++/Rust/MicroPython). Mind memory/timing constraints, interrupts, and hardware I/O.', 2),
        D('⛓ Smart Contract / Web3', 'A smart contract / web3 dApp (Solidity/etc.). Prioritize security (reentrancy, overflow), gas, and thorough tests.', 2),
        D('🔒 Security Hardening', 'Security hardening — input validation, authn/authz, secrets, injection, and dependency risks. Make minimal, safe changes.', 2),
        // --- by task type ---
        { name: '🛠 Feature Crew', group: 'task', max: 3, researcher: true, auditor: true, review: true },
        { name: '🐛 Bug Squad', group: 'task', max: 1, researcher: false, auditor: true, review: true },
        { name: '🧪 Test Crew', group: 'task', max: 2, researcher: false, auditor: true, review: true },
        { name: '♻️ Refactor Crew', group: 'task', max: 2, researcher: true, auditor: true, review: true },
        { name: '📝 Docs Crew', group: 'task', max: 1, researcher: false, auditor: false, review: false },
        { name: '🔍 Audit Crew', group: 'task', max: 2, researcher: true, auditor: true, review: true },
        { name: '⚡ Solo (fast)', group: 'task', max: 1, researcher: false, auditor: false, review: true }
    ],
    // ---- saved crew presets (team composition + per-role models, reusable) ----
    crewPresets: function () { try { var p = JSON.parse(localStorage.getItem('ade.crewPresets') || '{}'); return (p && typeof p === 'object') ? p : {}; } catch (e) { return {}; } },
    populatePresets: function () {
        var sel = $('#crewPreset'); if (!sel) return;
        var opt = function (t) { return '<option value="builtin:' + esc(t.name) + '">' + esc(t.name) + '</option>'; };
        var dom = this.CREW_TEAMS.filter(function (t) { return t.group === 'domain'; }).map(opt).join('');
        var task = this.CREW_TEAMS.filter(function (t) { return t.group !== 'domain'; }).map(opt).join('');
        var names = Object.keys(this.crewPresets());
        var saved = names.length ? '<optgroup label="Saved">' + names.map(function (n) { return '<option value="saved:' + esc(n) + '">' + esc(n) + '</option>'; }).join('') + '</optgroup>' : '';
        sel.innerHTML = '<option value="">— preset / team —</option>' +
            '<optgroup label="By project type">' + dom + '</optgroup>' +
            '<optgroup label="By task">' + task + '</optgroup>' + saved;
    },
    applyPreset: function (value) {
        var p;
        if (value.indexOf('builtin:') === 0) {
            var nm = value.slice(8);
            p = this.CREW_TEAMS.find(function (t) { return t.name === nm; });
        } else {
            p = this.crewPresets()[value.indexOf('saved:') === 0 ? value.slice(6) : value];
        }
        if (!p) return;
        var name = p.name || value.replace(/^saved:/, '');
        this.crewFocus = p.focus || ''; // domain steer the team passes to the crew
        if (p.max) $('#crewMax').value = String(p.max);
        if ('review' in p && $('#crewReview')) $('#crewReview').checked = !!p.review;
        if ('researcher' in p && $('#crewResearcher')) $('#crewResearcher').checked = !!p.researcher;
        if ('auditor' in p && $('#crewAuditor')) $('#crewAuditor').checked = !!p.auditor;
        this.setMval('crewModelDirector', p.directorModel);
        this.setMval('crewModelCoder', p.coderModel);
        this.setMval('crewModelAuditor', p.auditorModel);
        this.setMval('crewModelResearcher', p.researcherModel);
        var adv = document.querySelector('.crew-adv'); if (adv) adv.open = true;
        banner('loaded preset ' + name, 'ok');
    },
    savePreset: function () {
        var name = ($('#crewPresetName').value || '').trim() || $('#crewPreset').value;
        if (!name) { $('#crewPresetName').focus(); banner('name the preset first', 'err'); return; }
        var all = this.crewPresets();
        all[name] = {
            max: $('#crewMax').value || '2',
            review: $('#crewReview') ? $('#crewReview').checked : true,
            researcher: $('#crewResearcher') ? $('#crewResearcher').checked : true,
            auditor: $('#crewAuditor') ? $('#crewAuditor').checked : true,
            directorModel: this.mval('crewModelDirector'),
            coderModel: this.mval('crewModelCoder'),
            auditorModel: this.mval('crewModelAuditor'),
            researcherModel: this.mval('crewModelResearcher'),
            focus: this.crewFocus || ''
        };
        try { localStorage.setItem('ade.crewPresets', JSON.stringify(all)); } catch (e) {}
        this.populatePresets(); $('#crewPreset').value = 'saved:' + name; $('#crewPresetName').value = '';
        banner('saved preset ' + name, 'ok');
    },
    delPreset: function () {
        var v = $('#crewPreset').value;
        if (v.indexOf('saved:') !== 0) { banner('only your saved presets can be deleted', 'err'); return; }
        var name = v.slice(6);
        var all = this.crewPresets(); delete all[name];
        try { localStorage.setItem('ade.crewPresets', JSON.stringify(all)); } catch (e) {}
        this.populatePresets(); banner('deleted preset ' + name, 'ok');
    },
    // One screen: prompt the Director; the team handles the rest with smart
    // defaults (advanced section overrides if the user opened it).
    submitCrew: function () {
        var self = this;
        // Task is optional: leave it blank to launch the crew with the Director
        // waiting, then prompt it live in the crew terminal.
        var task = ($('#crewTask').value || '').trim();
        var go = function () {
            var opts = {
                max: $('#crewMax').value || '2',
                review: $('#crewReview') ? $('#crewReview').checked : true,
                researcher: $('#crewResearcher') ? $('#crewResearcher').checked : true,
                auditor: $('#crewAuditor') ? $('#crewAuditor').checked : true,
                directorModel: self.mval('crewModelDirector'),
                coderModel: self.mval('crewModelCoder'),
                auditorModel: self.mval('crewModelAuditor'),
                researcherModel: self.mval('crewModelResearcher'),
                focus: self.crewFocus || '',
                skills: self.crewTemplateSkills || [],
                hosts: ($('#crewHosts') && $('#crewHosts').value || '').trim()
            };
            self.closeCrew();
            self.runCrew(task, opts);
        };
        // If the user set a different folder in Advanced, switch to it first.
        var folder = ($('#crewFolder') && $('#crewFolder').value || '').trim();
        if (folder && folder !== this.cwd) {
            Promise.resolve(window.setRoot ? window.setRoot(folder) : { root: folder }).then(function (r) {
                if (!r || r.error) { banner('not a folder: ' + folder, 'err'); var a = document.querySelector('.crew-adv'); if (a) a.open = true; $('#crewFolder').focus(); return; }
                self.cwd = r.root; try { localStorage.setItem('ade.folder', r.root); } catch (e) {}
                self.loadFiles(r.root); go();
            }).catch(function (e) { banner('folder error: ' + e, 'err'); });
        } else { go(); }
    },
    // Launch `ollamadev crew "<task>"` in a fresh terminal and show it full-screen.
    runCrew: function (task, opts) {
        opts = opts || {};
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('close a terminal first (max ' + this.MAX_TERMINALS + ')', 'err'); return; }
        var base = opts.coderModel || opts.model || this.realModel();   // never the shell entry
        var cli = this.cli || 'ollamadev';
        var interactive = !String(task || '').trim();
        // With a task: run it. Without: launch interactive — the Director waits for
        // your prompt in this terminal.
        var q = interactive ? '' : " '" + String(task).replace(/'/g, "'\\''") + "'";
        var rmf = function (flag, m) { return m ? ' ' + flag + " '" + String(m).replace(/'/g, "'\\''") + "'" : ''; };
        // Run in the opened project folder, not the ADE's own directory.
        var cmd = this.cdPrefix() + cli + ' crew' + q + ' --max ' + (parseInt(opts.max, 10) || 2) + ' -m ' + base +
            (opts.review !== false ? ' --review' : '') +
            (opts.researcher === false ? ' --no-research' : '') +
            (opts.auditor === false ? ' --no-audit' : '') +
            rmf('--director-model', opts.directorModel) +
            rmf('--researcher-model', opts.researcher !== false ? opts.researcherModel : '') +
            rmf('--auditor-model', opts.auditor !== false ? opts.auditorModel : '') +
            rmf('--focus', opts.focus) +
            (Array.isArray(opts.skills) ? opts.skills.map(function (s) { return rmf('--skill', s); }).join('') : '') +
            (opts.hosts ? ' --hosts ' + "'" + String(opts.hosts).replace(/\s+/g, '').replace(/'/g, "'\\''") + "'" : '');
        var model = base;
        // Use the REAL model (not the literal 'crew') so a saved/resumed crew terminal
        // relaunches with -m <model>, not the invalid "-m crew".
        var dir = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        var id = rid(); var t = new Terminal(id, model, dir); t.kind = 'crew';
        var self = this;
        Promise.resolve(window.termCreate(id, model, dir)).then(function () {
            self.terminals.push(t); self.render();
            setTimeout(function () { try { window.termWrite(id, strToB64(cmd + '\n')); } catch (e) {} }, 400);
            // Interactive: stay on the terminal so you can prompt the Director.
            // With a task: jump to the board so the plan appears as it's made.
            self.setView(interactive ? 'code' : 'board');
            self.startCrewPoll();
            self.openDirectorTerminal();   // the Director gets its own terminal to steer from
            banner(interactive ? 'crew ready — prompt the Director in the terminal' : 'crew running…', 'ok');
        }).catch(function (e) { banner('crew launch failed: ' + e, 'err'); });
    },
    // Open a dedicated terminal running the Director steering console (`crew director`),
    // so you redirect coders from its own tab instead of the coder output stream.
    openDirectorTerminal: function () {
        var self = this;
        if (this.terminals.some(function (t) { return t.kind === 'director'; })) return;   // already open
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('close a terminal to open the Director', 'err'); return; }
        var cli = this.cli || 'ollamadev';
        var cmd = this.cdPrefix() + cli + ' crew director';
        var dir = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        var id = rid(); var t = new Terminal(id, 'Director', dir); t.kind = 'director';
        Promise.resolve(window.termCreate(id, 'Director', dir)).then(function () {
            self.live[id] = true; self.terminals.push(t); self.render();
            setTimeout(function () { try { window.termWrite(id, strToB64(cmd + '\n')); } catch (e) {} }, 550);
        }).catch(function () {});
    },
    // Spawn a terminal in `folder` that runs a specific command. Used to RESTORE crew
    // and director terminals to their real command (e.g. `crew director`) instead of a
    // bare `-m <label>`, which is why they didn't come back after a restart.
    spawnCmd: function (model, kind, folder, cmd) {
        if (this.terminals.length >= this.MAX_TERMINALS) return;
        var dir = this.expandHome(folder) || (this.cwd && this.cwd !== '.' ? this.cwd : '');
        var id = rid(); var t = new Terminal(id, model, dir); if (kind) t.kind = kind;
        var self = this;
        Promise.resolve(window.termCreate(id, model, dir)).then(function () {
            self.live[id] = true; self.terminals.push(t); self.render();
            if (cmd) setTimeout(function () { try { window.termWrite(id, strToB64(cmd + '\n')); } catch (e) {} }, 500);
        }).catch(function () {});
    },
    // Poll the live crew board so the kanban reflects the Director's plan + progress.
    startCrewPoll: function () {
        var self = this;
        if (this.crewPoll) return;
        var idle = 0;
        this.crewPoll = setInterval(function () {
            Promise.resolve(window.crewBoard ? window.crewBoard() : null).then(function (b) {
                // A "clear the board" (agent tool / `crew clear` / CLI) writes a cleared
                // sentinel. Apply it once via a localStorage watermark — survives poll &
                // app restarts — wiping the localStorage-only manual cards the engine
                // can't reach. (Crew cards/ideas clear via the empty board below.)
                if (b && b.cleared) {
                    var seen = 0; try { seen = parseInt(localStorage.getItem('ade.boardCleared') || '0', 10) || 0; } catch (e) {}
                    if (b.cleared > seen) {
                        try { localStorage.setItem('ade.boardCleared', String(b.cleared)); } catch (e) {}
                        try { localStorage.removeItem('ade.tasks'); } catch (e) {}
                        Tasks.items = [];
                    }
                }
                self.crewBoard = (b && b.subtasks) ? b : self.crewBoard;
                if (self.view === 'board') { Tasks.render(); CrewPanes.sync(self.crewBoard); }
                // Stop polling a while after the run goes inactive.
                if (b && b.active === false) { if (++idle > 6) { clearInterval(self.crewPoll); self.crewPoll = null; } }
                else idle = 0;
            }).catch(function () {});
        }, 1500);
    },
    // Mobile (web mode): the sidebar is an off-canvas drawer. The rail opens it;
    // tapping the workspace, hitting Esc, or picking a file closes it. No-ops on
    // desktop where the sidebar is always docked.
    initResponsive: function () {
        var small = function () { return window.matchMedia('(max-width: 820px)').matches; };
        var open = function () { if (small()) document.body.classList.add('nav-open'); };
        var close = function () { document.body.classList.remove('nav-open'); };
        var rail = $('#rail'); if (rail) rail.addEventListener('click', open);
        var ws = $('#workspace'); if (ws) ws.addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        var sb = $('#sidebar'); if (sb) sb.addEventListener('click', function (e) {
            if (e.target.closest('.tree-item')) setTimeout(close, 60); // file picked → reveal the editor
        });
    },
    toggleZoom: function (id) {
        this.zoomed = this.zoomed === id ? null : id;
        this.render();
    },
    markActiveFile: function (path) {
        document.querySelectorAll('#fileTree .tree-item').forEach(function (el) {
            el.classList.toggle('active', !!path && el.dataset.type === 'file' && el.dataset.path === path);
        });
    },
    loadModels: function () {
        return Promise.resolve(window.listModels()).then(function (s) {
            var sel = $('#modelSelect'); var conn = $('#conn');
            if (conn) {
                conn.className = 'conn' + (s && s.connected ? ' on' : '');
                conn.title = s && s.connected ? 'Ollama connected' : 'Ollama not reachable';
            }
            if (sel) {
                var models = (s && s.models) || [];
                var def = (s && s.default) || '';   // your configured ollama.defaultModel
                // "shell" sits at the top of the model list: pick it + "+ Terminal" for a
                // plain shell (no ollamadev). It's never the default selection.
                var shellOpt = '<option value="shell">🐚 shell — plain terminal</option>';
                sel.innerHTML = shellOpt + (models.map(function (m) {
                    var name = m.name || m;
                    return '<option' + (name === def ? ' selected' : '') + '>' + esc(name) + '</option>';
                }).join('') || '<option>llama3.2:latest</option>');
                // If the default isn't in the list (e.g. not pulled), still select it.
                if (def && sel.value !== def && [].some.call(sel.options, function (o) { return o.value === def; })) sel.value = def;
            }
        }).catch(function (e) { banner('listModels failed: ' + e, 'err'); });
    },
    loadFiles: function (path) {
        var self = this;
        Promise.resolve(window.listFiles(path)).then(function (items) {
            if (items && items.error) { banner('list failed: ' + items.error, 'err'); return; }
            if (!Array.isArray(items)) return;
            self.cwd = path;
            var cwdEl = $('#cwd'); if (cwdEl) { cwdEl.textContent = shortPath(path); cwdEl.title = path; }
            var bc = $('#breadcrumb'); if (bc) { bc.textContent = shortPath(path, 60); bc.title = path; }
            var tree = $('#fileTree');
            var html = '';
            // Parent (".." ) entry, unless we're at the filesystem root.
            var parent = path.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') || '/';
            if (parent && parent !== path) {
                html += '<div class="tree-item up" data-path="' + esc(parent) + '" data-type="dir" data-name="..">📁 ..</div>';
            }
            html += items.map(function (it) {
                return '<div class="tree-item" data-path="' + esc(it.path) + '" data-type="' + esc(it.type) + '" data-name="' + esc(it.name) + '">' +
                    (it.type === 'dir' ? '📁' : '📄') + ' ' + esc(it.name) + '</div>';
            }).join('');
            tree.innerHTML = html;
            tree.querySelectorAll('.tree-item').forEach(function (el) {
                el.onclick = function () {
                    if (el.dataset.type === 'dir') self.loadFiles(el.dataset.path);
                    else Editor.open(el.dataset.path, el.dataset.name);
                };
            });
            self.markActiveFile(Editor.cur() ? Editor.cur().path : null);
        }).catch(function (e) { banner('list error: ' + e, 'err'); });
    },
    MAX_TERMINALS: 16,
    // Auto-launch the interactive ollamadev CLI (with the selected model) inside
    // a freshly-started pty. You can still switch models in the CLI with /model.
    launchCli: function (id, model, cwd) {
        // SIMPLE_INPUT: the CLI uses plain line input so the embedded terminal
        // (which the host pty echoes into) renders cleanly without raw-mode escapes.
        var cmd = this.cdPrefix(cwd) + 'OLLAMADEV_SIMPLE_INPUT=1 ' + (this.cli || 'ollamadev') + (model ? ' -m ' + model : '') + '\n';
        // Small delay so the pty shell is ready to receive the command.
        setTimeout(function () { try { window.termWrite(id, strToB64(cmd)); } catch (e) {} }, 350);
    },
    // A real (non-shell) model for paths that must launch an agent (run-task, crew).
    realModel: function () {
        var sel = $('#modelSelect');
        var v = sel && sel.value;
        if (v && v !== 'shell') return v;
        var opts = sel ? Array.prototype.slice.call(sel.options).map(function (o) { return o.value; }).filter(function (m) { return m !== 'shell'; }) : [];
        return opts[0] || 'llama3.2:latest';
    },
    // Respawn a terminal in a new working folder (the per-terminal folder chip).
    changeTermFolder: function (id, folder) {
        var t = this.terminals.find(function (x) { return x.id === id; });
        if (!t) return;
        folder = this.expandHome(folder); if (!folder) return;
        var self = this;
        Promise.resolve(window.termKill ? window.termKill(id) : null).then(function () {
            t.cwd = folder; t.offset = 0; t.screen = null;
            return window.termCreate(id, t.model, folder);
        }).then(function () {
            self.render();
            self.launchCli(id, t.model, folder);
            banner('terminal folder → ' + folder, 'ok');
        }).catch(function () { banner('could not change folder', 'err'); });
    },
    newTerminal: function () { return this.spawnTerminal(); },
    // Spawn a brand-new pty + launch the CLI in it.
    spawnTerminal: function (model, folder) {
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('maximum of ' + this.MAX_TERMINALS + ' terminals', 'err'); return; }
        model = model || $('#modelSelect').value || 'llama3.2:latest';
        var dir = this.expandHome(folder) || (this.cwd && this.cwd !== '.' ? this.cwd : '');
        var id = rid();
        var isShell = (model === 'shell');   // the "shell" entry at the top of the model list
        var t = new Terminal(id, model, dir); if (isShell) t.kind = 'shell';
        var self = this;
        Promise.resolve(window.termCreate(id, model, dir)).then(function () {
            self.live[id] = true;
            self.terminals.push(t);
            self.render();
            if (!isShell) self.launchCli(id, model, dir);   // shell: bare prompt, no ollamadev
        }).catch(function (e) { banner('termCreate failed: ' + e, 'err'); });
    },
    // Re-attach the UI to a pty that's still alive from earlier this session
    // (workspace switch). No termCreate, no launchCli — poll resumes its buffer.
    attachTerminal: function (id, model, kind, cwd) {
        if (this.terminals.length >= this.MAX_TERMINALS) return;
        var t = new Terminal(id, model || $('#modelSelect').value || 'llama3.2:latest', cwd || '');
        if (kind) t.kind = kind;
        this.terminals.push(t);
    },
    closeTerminal: function (id) {
        var i = this.terminals.findIndex(function (t) { return t.id === id; });
        if (i >= 0) { this.terminals[i].close(); delete this.live[id]; this.terminals.splice(i, 1); this.render(); }
    },
    // Keep the active workspace's window state persisted so reopening the app
    // RESUMES it. Previously state was only saved when switching away from a
    // workspace, so working in one project and just closing the app lost the
    // session. This polls captureState() and writes only when it actually
    // changed (cheap), plus a final flush when the window goes away.
    startAutosave: function () {
        var self = this;
        var flush = function () {
            // Skip the transient empty window mid-switch (terminals detached before
            // the new ones spawn) so we never persist an empty state over a project.
            if (!Workspaces.data.active || !self.terminals.length) return;
            var snap; try { snap = JSON.stringify(self.captureState()); } catch (e) { return; }
            if (snap === self._lastSnap) return;          // unchanged → skip the write
            self._lastSnap = snap;
            try { Workspaces.saveCurrentState(); } catch (e) {}
        };
        setInterval(flush, 4000);
        window.addEventListener('beforeunload', flush);
        window.addEventListener('pagehide', flush);
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
    },
    // Drop every terminal's UI but leave its pty running (workspace switch).
    detachTerminals: function () {
        this.terminals.forEach(function (t) { t.detach(); });
        this.terminals = [];
        this.zoomed = null;
        this.render();
    },
    // Snapshot the current window so a workspace can be restored exactly.
    captureState: function () {
        return {
            terminals: this.terminals.map(function (t) { return { id: t.id, model: t.model, kind: t.kind || '', cwd: t.cwd || '', x: t.x, y: t.y, w: t.w, h: t.h, z: t.z }; }),
            editorTabs: Editor.tabs.map(function (t) { return { path: t.path, name: t.name }; }),
            layout: this.layout,
            view: this.view,
            zoomed: this.zoomed || null
        };
    },
    // Rebuild the window from a saved workspace state. Re-attaches terminals whose
    // pty survived this session; respawns the rest fresh (e.g. after a restart).
    restoreState: function (state) {
        state = state || {};
        var self = this;
        Editor.closeAll();
        (state.editorTabs || []).forEach(function (t) { Editor.open(t.path, t.name); });
        var terms = state.terminals || [];
        // Free-layout: the MODE is global (set in init from localStorage); here we only
        // restore each pane's saved geometry (by id for re-attached, by index for
        // respawned). applyGeom consumes these on the first free render.
        this._geomQueue = terms.map(function (g) { return { x: g.x, y: g.y, w: g.w, h: g.h, z: g.z }; });
        this._geomById = {};
        terms.forEach(function (g) { if (g.id) self._geomById[g.id] = { x: g.x, y: g.y, w: g.w, h: g.h, z: g.z }; if (g.z > self._zTop) self._zTop = g.z; });
        var attached = 0;
        terms.forEach(function (ti) { if (self.live[ti.id]) { self.attachTerminal(ti.id, ti.model, ti.kind, ti.cwd); attached++; } });
        this.zoomed = state.zoomed || null;
        if (attached) this.render();
        // saved terminals whose pty is gone → respawn (each renders itself). Restore by
        // KIND so the Director console + crew prompt come back as themselves, not a bare
        // `-m <label>`; "shell" comes back as a plain shell; the rest relaunch ollamadev.
        var cli = self.cli || 'ollamadev';
        terms.forEach(function (ti) {
            if (self.live[ti.id]) return;
            if (ti.kind === 'director') self.spawnCmd('Director', 'director', ti.cwd, cli + ' crew director');
            else if (ti.kind === 'crew') self.spawnCmd(ti.model, 'crew', ti.cwd, cli + ' crew');
            else self.spawnTerminal(ti.model, ti.cwd);
        });
        if (!terms.length && !this.terminals.length) this.spawnTerminal();
        if (state.layout) this.setLayout(state.layout);
        if (state.view) this.setView(state.view);
    },
    render: function () {
        var wrap = $('#terminals');
        if (this.termLayout === 'free') return this.renderFree(wrap);
        // A zoomed terminal takes the whole area; otherwise the CSS responsive
        // grid fits as many readable-width panes as possible and scrolls for more.
        // Hoist zoomed into a local: the callbacks below must not read `this`
        // (a bare .filter/.some callback runs with this===undefined in strict mode).
        var z = this.zoomed;
        if (z && !this.terminals.some(function (t) { return t.id === z; })) { this.zoomed = null; z = null; }
        var list = z ? this.terminals.filter(function (t) { return t.id === z; }) : this.terminals;
        var n = list.length;
        // Fit all panes; shrink the font as the count rises so code stays legible.
        var cols = z || n <= 1 ? 1 : Math.min(4, Math.ceil(Math.sqrt(n)));
        var fs = z ? 13 : n <= 2 ? 13 : n <= 4 ? 12 : n <= 6 ? 11 : n <= 9 ? 10 : 9;
        wrap.className = (z ? 'zoomed' : '') + (!z && n > 6 ? ' dense' : '');
        wrap.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, 1fr))';
        wrap.style.setProperty('--tfs', fs + 'px');
        // Focus = pop the terminal out to fill the whole work area (over the editor),
        // not just the terminals strip. Cleared when un-focused.
        var cv = $('#codeView'); if (cv) cv.classList.toggle('term-zoom', !!z);
        wrap.innerHTML = '';
        list.forEach(function (t) {
            var pane = document.createElement('div');
            pane.className = 'term-pane';
            wrap.appendChild(pane);
            t.mount(pane);
        });
    },
    // ---- Free-floating layout: drag the header, resize the corner, overlap freely ----
    setTermLayout: function (mode) {
        this.termLayout = (mode === 'free') ? 'free' : 'tiled';
        try { localStorage.setItem('ade.termLayout', this.termLayout); } catch (e) {}   // reopen here next time
        var btn = $('#termArrange'); if (btn) btn.textContent = this.termLayout === 'free' ? '⮻ Free' : '⊞ Tiled';
        if (this.termLayout === 'free') this.zoomed = null;   // zoom is a tiled-only concept
        this.render();
        if (Workspaces && Workspaces.saveCurrentState) Workspaces.saveCurrentState();
    },
    renderFree: function (wrap) {
        var self = this;
        wrap.className = 'free';
        wrap.style.gridTemplateColumns = '';
        wrap.style.setProperty('--tfs', '13px');
        var cv = $('#codeView'); if (cv) cv.classList.remove('term-zoom');
        wrap.innerHTML = '';
        this.terminals.forEach(function (t, i) {
            if (typeof t.x !== 'number') self.applyGeom(t, i);
            var pane = document.createElement('div');
            pane.className = 'term-pane';
            pane.style.left = t.x + 'px'; pane.style.top = t.y + 'px';
            pane.style.width = t.w + 'px'; pane.style.height = t.h + 'px';
            pane.style.zIndex = t.z || 1;
            wrap.appendChild(pane);
            t.mount(pane);
            var rh = document.createElement('div'); rh.className = 'term-resize'; pane.appendChild(rh);
            self.wireFree(t, pane, rh);
        });
    },
    // Pick a starting geometry: a saved one (by index, from restore) or a cascade.
    applyGeom: function (t, i) {
        var g = (this._geomById && this._geomById[t.id]) || (this._geomQueue && this._geomQueue[i]) || null;
        if (g && typeof g.x === 'number') { t.x = g.x; t.y = g.y; t.w = g.w; t.h = g.h; t.z = g.z || ++this._zTop; }
        else { t.x = 24 + (i * 30) % 240; t.y = 24 + (i * 30) % 170; t.w = 540; t.h = 340; t.z = ++this._zTop; }
    },
    _zTop: 1,
    bringFront: function (t, pane) { t.z = ++this._zTop; pane.style.zIndex = t.z; },
    saveGeomSoon: function () {
        var self = this; clearTimeout(this._geomSave);
        this._geomSave = setTimeout(function () { if (Workspaces && Workspaces.saveCurrentState) Workspaces.saveCurrentState(); }, 400);
    },
    wireFree: function (t, pane, rh) {
        var self = this;
        pane.addEventListener('mousedown', function () { self.bringFront(t, pane); });
        var head = pane.querySelector('.term-head');
        if (head) head.addEventListener('mousedown', function (e) {
            if (e.target.closest('.zoom, .x, .term-cd, .term-cd-edit, .badge')) return;   // let buttons work
            e.preventDefault();
            var sx = e.clientX, sy = e.clientY, ox = t.x, oy = t.y;
            function mv(ev) { t.x = Math.max(0, ox + ev.clientX - sx); t.y = Math.max(0, oy + ev.clientY - sy); pane.style.left = t.x + 'px'; pane.style.top = t.y + 'px'; }
            function up() { document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); document.body.style.userSelect = ''; self.saveGeomSoon(); }
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
        });
        rh.addEventListener('mousedown', function (e) {
            e.preventDefault(); e.stopPropagation();
            var sx = e.clientX, sy = e.clientY, ow = t.w, oh = t.h;
            function mv(ev) { t.w = Math.max(240, ow + ev.clientX - sx); t.h = Math.max(130, oh + ev.clientY - sy); pane.style.width = t.w + 'px'; pane.style.height = t.h + 'px'; }
            function up() { document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); document.body.style.userSelect = ''; self.saveGeomSoon(); }
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
        });
    }
};

// ---------- boot (wait for Boson bindings, then start) ----------
(function boot() {
    var waited = 0;
    function ready() { return typeof window.listModels === 'function' && typeof window.termCreate === 'function'; }
    function go() {
        if (ready()) { try { App.init(); } catch (e) { banner('init error: ' + e.message, 'err'); } }
        else if (waited < 15000) { waited += 50; banner('waiting for bindings… ' + waited + 'ms'); setTimeout(go, 50); }
        else { banner('bindings unavailable', 'err'); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
window.app = App;
