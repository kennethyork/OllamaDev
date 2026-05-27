import * as vscode from 'vscode';
import * as net from 'net';
import * as cp from 'child_process';

interface LSPMessage {
    jsonrpc: string;
    id?: number | string;
    method?: string;
    params?: any;
    result?: any;
    error?: any;
}

let lspProcess: cp.ChildProcess | null = null;
let clientSocket: net.Socket | null = null;
let pendingRequests = new Map<string, (response: LSPMessage) => void>();
let requestId = 0;
let statusBarItem: vscode.StatusBarItem;
let completionEnabled = false;
let chatPanel: vscode.WebviewPanel | null = null;

function getConfig() {
    const cfg = vscode.workspace.getConfiguration('ollamadev');
    return {
        port: cfg.get<number>('port', 4389),
        hostname: cfg.get<string>('hostname', '127.0.0.1'),
        autoStart: cfg.get<boolean>('autoStart', true),
        model: cfg.get<string>('model', 'llama3.2:latest'),
        inlineCompletionEnabled: cfg.get<boolean>('inlineCompletionEnabled', false),
        statusBarEnabled: cfg.get<boolean>('statusBarEnabled', true)
    };
}

function updateStatus(connected: boolean, message = '') {
    const config = getConfig();
    if (!config.statusBarEnabled) { statusBarItem.hide(); return; }
    statusBarItem.text = connected ? '$(AI) OllamaDev: Connected' : '$(error) OllamaDev: Disconnected';
    statusBarItem.tooltip = message || (connected ? 'OllamaDev connected' : 'OllamaDev disconnected');
    statusBarItem.show();
}

function createStatusBar() {
    statusBarItem = vscode.window.createStatusBarItem('ollamadev.status', vscode.StatusBarAlignment.Right, 100);
    statusBarItem.text = '$(sync) OllamaDev: Starting...';
    statusBarItem.command = 'ollamadev-lsp.status';
    statusBarItem.show();
}

function connect(): Promise<net.Socket> {
    return new Promise((resolve, reject) => {
        const { port, hostname } = getConfig();
        const socket = net.createConnection(port, hostname);
        socket.on('connect', () => { clientSocket = socket; resolve(socket); });
        socket.on('error', (err) => { reject(err); });
        socket.on('close', () => { clientSocket = null; updateStatus(false, 'Connection closed'); });
    });
}

async function sendLSPRequest(method: string, params: any): Promise<any> {
    if (!clientSocket || clientSocket.destroyed) { await connect(); }
    const id = String(++requestId);
    const msg: LSPMessage = { jsonrpc: '2.0', id, method, params };
    return new Promise((resolve, reject) => {
        pendingRequests.set(id, (response) => { response.error ? reject(response.error) : resolve(response.result); });
        const data = JSON.stringify(msg);
        clientSocket!.write("Content-Length: " + Buffer.byteLength(data) + "\r\n\r\n" + data);
        setTimeout(() => { if (pendingRequests.has(id)) { pendingRequests.delete(id); reject(new Error('timeout')); } }, 30000);
    });
}

function setupSocketHandlers() {
    if (!clientSocket) return;
    let buffer = '';
    clientSocket.on('data', (data: Buffer) => {
        buffer += data.toString();
        while (true) {
            const headerEnd = buffer.indexOf('\r\n\r\n');
            if (headerEnd === -1) break;
            const header = buffer.substring(0, headerEnd);
            const bodyStart = headerEnd + 4;
            let contentLength = 0;
            for (const line of header.split('\r\n')) {
                if (line.startsWith('Content-Length:')) contentLength = parseInt(line.substring(15).trim(), 10);
            }
            if (buffer.length < bodyStart + contentLength) break;
            const body = buffer.substring(bodyStart, bodyStart + contentLength);
            buffer = buffer.substring(bodyStart + contentLength);
            try {
                const msg: LSPMessage = JSON.parse(body);
                if (msg.id !== undefined && pendingRequests.has(String(msg.id))) {
                    const resolve = pendingRequests.get(String(msg.id))!;
                    pendingRequests.delete(String(msg.id));
                    resolve(msg);
                }
            } catch {}
        }
    });
}

async function initialize(): Promise<void> {
    await sendLSPRequest('initialize', { processId: process.pid, clientInfo: { name: 'ollamadev-vscode', version: '1.1.0' }, capabilities: {} });
    await sendLSPRequest('initialized', {});
}

async function aiRequest(method: string, params: any): Promise<string> {
    try {
        const result = await sendLSPRequest(method, params);
        return result?.reply || result?.content || JSON.stringify(result);
    } catch (err) { throw err; }
}

class InlineCompletionProvider implements vscode.InlineCompletionItemProvider {
    public enabled = false;
    private lastCompletion = '';
    private pendingCompletions = new Map<string, CancelableCompletion>();

    async provideInlineCompletionItems(document: vscode.TextDocument, position: vscode.Position, context: vscode.InlineCompletionContext, token: vscode.CancellationToken): Promise<vscode.InlineCompletionItem[] | null> {
        if (!this.enabled && !getConfig().inlineCompletionEnabled) return null;
        
        const timeout = new Promise<null>((_, reject) => setTimeout(() => reject(new Error('timeout')), 5000));
        
        try {
            const docText = document.getText(new vscode.Range(new vscode.Position(0, 0), position));
            const line = document.lineAt(position.line).text;
            const cursor = line.substring(0, position.character);
            
            const completionPromise = this.getStreamingCompletion(docText, cursor, token);
            const result = await Promise.race([completionPromise, timeout]) as { text: string; isPartial: boolean } | null;
            
            if (token.isCancellationRequested) return null;
            if (result && result.text) {
                this.lastCompletion = result.text;
                return [new vscode.InlineCompletionItem(
                    new vscode.SnippetString(result.text),
                    undefined,
                    { command: 'ollamadev.complete.accepted', title: 'OllamaDev AI' }
                )];
            }
        } catch (err) { console.error('completion error:', err); }
        return null;
    }

    private async getStreamingCompletion(code: string, cursor: string, token: vscode.CancellationToken): Promise<{ text: string; isPartial: boolean } | null> {
        return new Promise((resolve, reject) => {
            let result = '';
            let resolved = false;
            
            const timeout = setTimeout(() => {
                if (!resolved) {
                    resolved = true;
                    resolve({ text: result, isPartial: false });
                }
            }, 3000);

            sendLSPRequest('textDocument/completion', {
                textDocument: { uri: 'file://completion' },
                position: { line: 0, character: cursor.length },
                context: { triggerCharacter: cursor.slice(-1) }
            }).then((resp: any) => {
                clearTimeout(timeout);
                if (!resolved) {
                    resolved = true;
                    const items = resp?.items || [];
                    const insertText = items[0]?.insertText || '';
                    resolve({ text: insertText, isPartial: false });
                }
            }).catch(err => {
                clearTimeout(timeout);
                if (!resolved) {
                    resolved = true;
                    reject(err);
                }
            });

            token.onCancellationRequested(() => {
                clearTimeout(timeout);
                if (!resolved) {
                    resolved = true;
                    resolve({ text: result, isPartial: true });
                }
            });
        });
    }
}

interface CancelableCompletion {
    cancel: () => void;
    promise: Promise<{ text: string; isPartial: boolean }>;
}

function createChatPanel(context: vscode.ExtensionContext) {
    if (chatPanel) { chatPanel.reveal(); return; }
    chatPanel = vscode.window.createWebviewPanel('ollamadev-chat', 'OllamaDev Chat', vscode.ViewColumn.Two, { enableScripts: true });
    chatPanel.webview.html = `<!DOCTYPE html>
<html><head><style>
body { font-family: system-ui; padding: 20px; background: #1e1e1e; color: #ccc; }
#messages { height: 60vh; overflow-y: auto; border: 1px solid #333; padding: 10px; margin-bottom: 10px; }
.message { margin: 10px 0; padding: 10px; border-radius: 5px; }
.user { background: #0d47a1; text-align: right; }
.assistant { background: #1b5e20; }
.input-area { display: flex; gap: 10px; }
#input { flex: 1; padding: 10px; background: #2d2d2d; color: #fff; border: 1px solid #333; }
button { padding: 10px 20px; background: #00695c; color: #fff; border: none; cursor: pointer; }
button:hover { background: #00897b; }
</style></head><body>
<h1>OllamaDev Chat</h1>
<div id="messages"></div>
<div class="input-area">
    <input id="input" placeholder="Ask about code..." />
    <button onclick="sendMessage()">Send</button>
</div>
<script>
const vscode = acquireVsCodeApi();
const messagesDiv = document.getElementById('messages');
function addMessage(role, content) {
    const div = document.createElement('div');
    div.className = role;
    div.textContent = content;
    messagesDiv.appendChild(div);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}
function sendMessage() {
    const input = document.getElementById('input');
    const text = input.value.trim();
    if (!text) return;
    addMessage('user', text);
    input.value = '';
    vscode.postMessage({ type: 'chat', text });
}
document.getElementById('input').addEventListener('keypress', e => { if (e.key === 'Enter') sendMessage(); });
window.addEventListener('message', e => {
    if (e.data.type === 'response') addMessage('assistant', e.data.text);
    if (e.data.type === 'error') addMessage('assistant', 'Error: ' + e.data.text);
});
</script></body></html>`;

    chatPanel.webview.onDidReceiveMessage(async (msg) => {
        if (msg.type === 'chat') {
            try {
                const response = await aiRequest('ollamadev/chat', { message: msg.text });
                chatPanel?.webview.postMessage({ type: 'response', text: response });
            } catch (err) {
                chatPanel?.webview.postMessage({ type: 'error', text: String(err) });
            }
        }
    });

    chatPanel.onDidDispose(() => { chatPanel = null; });
}

function findOllamadevBinary(): string | null {
    const paths = process.platform === 'win32' 
        ? ['ollamadev.bat', 'ollamadev.exe', 'C:\\Program Files\\OllamaDev\\ollamadev.exe', 'C:\\Users\\' + process.env.USERNAME + '\\AppData\\Local\\ollamadev\\ollamadev.exe']
        : ['ollamadev', '/usr/local/bin/ollamadev', '/usr/bin/ollamadev', process.env.HOME + '/.local/bin/ollamadev'];
    
    for (const p of paths) {
        try {
            cp.execSync(p + ' --version', { stdio: 'ignore' });
            return p;
        } catch {}
    }
    return null;
}

async function startLSPProcess() {
    const config = getConfig();
    const lspPath = findOllamadevBinary();
    
    if (config.autoStart && lspPath) {
        try {
            const args = ['lsp', '--port', String(config.port), '--hostname', config.hostname];
            const options: any = { stdio: 'pipe' };
            if (process.platform === 'win32') {
                lspProcess = cp.spawn('cmd.exe', ['/c', 'start', '/b', lspPath, ...args], options);
            } else {
                lspProcess = cp.spawn(lspPath, args, { stdio: 'ignore', detached: true });
                lspProcess.unref();
            }
            await new Promise(r => setTimeout(r, 1500));
        } catch (err) { console.error('LSP start error:', err); }
    }
    try {
        await connect();
        setupSocketHandlers();
        await initialize();
        updateStatus(true, 'LSP connected');
        vscode.window.showInformationMessage('OllamaDev connected');
    } catch (err) {
        updateStatus(false, 'Failed to connect');
        vscode.window.showErrorMessage('Failed to connect to OllamaDev. Run: ollamadev lsp');
    }
}

function stopLSP() {
    if (clientSocket) { clientSocket.destroy(); clientSocket = null; }
    if (lspProcess) { lspProcess.kill(); lspProcess = null; }
    updateStatus(false, 'Stopped');
}

async function restartLSP() { stopLSP(); await startLSPProcess(); }
function showStatus() { vscode.window.showInformationMessage(clientSocket && !clientSocket.destroyed ? 'OllamaDev: Connected' : 'Ollamadev: Disconnected'); }

async function generateCode() {
    const editor = vscode.window.activeTextEditor;
    if (!editor) { vscode.window.showErrorMessage('No active editor'); return; }
    const selection = editor.selection;
    const text = selection.isEmpty ? editor.document.getText() : editor.document.getText(selection);
    if (!text) { vscode.window.showErrorMessage('No code selected'); return; }
    try {
        const result = await aiRequest('ollamadev/generate', { code: text });
        editor.edit(editBuilder => { editBuilder.insert(selection.end, '\n' + result); });
    } catch (err) { vscode.window.showErrorMessage('Generation failed: ' + err); }
}

async function reviewCode() {
    const editor = vscode.window.activeTextEditor;
    if (!editor) { vscode.window.showErrorMessage('No active editor'); return; }
    const text = editor.document.getText();
    if (!text) { vscode.window.showErrorMessage('No code in editor'); return; }
    try {
        const result = await aiRequest('ollamadev/review', { code: text });
        const doc = await vscode.workspace.openTextDocument({ content: `# Code Review\n\n${result}`, language: 'markdown' });
        await vscode.window.showTextDocument(doc, vscode.ViewColumn.One);
    } catch (err) { vscode.window.showErrorMessage('Review failed: ' + err); }
}

async function askAI() {
    const editor = vscode.window.activeTextEditor;
    if (!editor) { vscode.window.showErrorMessage('No active editor'); return; }
    const selection = editor.selection;
    const text = selection.isEmpty ? editor.document.getText() : editor.document.getText(selection);
    const question = await vscode.window.showInputBox({ prompt: 'Ask OllamaDev about the code', placeHolder: 'What does this code do?' });
    if (question) {
        try {
            const result = await aiRequest('ollamadev/chat', { message: `Code:\n${text}\n\nQuestion: ${question}` });
            const doc = await vscode.workspace.openTextDocument({ content: `# Answer\n\n${result}`, language: 'markdown' });
            await vscode.window.showTextDocument(doc, vscode.ViewColumn.One);
        } catch (err) { vscode.window.showErrorMessage('Ask failed: ' + err); }
    }
}

async function getCompletion() {
    const editor = vscode.window.activeTextEditor;
    if (!editor) { vscode.window.showErrorMessage('No active editor'); return; }
    const position = editor.selection.active;
    try {
        const result = await sendLSPRequest('textDocument/completion', {
            textDocument: { uri: editor.document.uri.toString() },
            position: { line: position.line, character: position.character },
            context: {}
        });
        const items = result?.items || [];
        if (items.length > 0 && items[0].insertText) {
            const edit = new vscode.WorkspaceEdit();
            edit.insert(editor.document.uri, position, items[0].insertText);
            await vscode.workspace.applyEdit(edit);
        }
    } catch (err) { vscode.window.showErrorMessage('Completion failed: ' + err); }
}

function toggleInlineCompletion(provider: InlineCompletionProvider) {
    provider.enabled = !provider.enabled;
    const config = vscode.workspace.getConfiguration('ollamadev');
    config.update('inlineCompletionEnabled', provider.enabled, true);
    vscode.window.showInformationMessage('OllamaDev inline completion: ' + (provider.enabled ? 'ON' : 'OFF'));
}

async function formatDocument() {
    const editor = vscode.window.activeTextEditor;
    if (!editor) { vscode.window.showErrorMessage('No active editor'); return; }
    try {
        const doc = editor.document;
        const result = await sendLSPRequest('textDocument/formatting', { textDocument: { uri: doc.uri.toString() } });
        if (result && Array.isArray(result) && result.length > 0) {
            const edit = new vscode.WorkspaceEdit();
            for (const change of result) {
                const range = new vscode.Range(
                    change.range.start.line, change.range.start.character,
                    change.range.end.line, change.range.end.character
                );
                edit.replace(doc.uri, range, change.newText);
            }
            await vscode.workspace.applyEdit(edit);
            vscode.window.showInformationMessage('Document formatted');
        } else {
            vscode.window.showInformationMessage('No formatting changes needed');
        }
    } catch (err) { vscode.window.showErrorMessage('Format failed: ' + err); }
}

async function quickFix() {
    const editor = vscode.window.activeTextEditor;
    if (!editor) { vscode.window.showErrorMessage('No active editor'); return; }
    const doc = editor.document;
    const selection = editor.selection;
    try {
        const result = await sendLSPRequest('textDocument/codeAction', {
            textDocument: { uri: doc.uri.toString() },
            range: { start: { line: selection.start.line, character: selection.start.character }, end: { line: selection.end.line, character: selection.end.character } },
            context: { diagnostics: [] }
        });
        if (result && result.length > 0) {
            vscode.commands.executeCommand('editor.action.applyCodeAction', result[0]);
        } else {
            vscode.window.showInformationMessage('No code actions available');
        }
    } catch (err) { vscode.window.showErrorMessage('Quick fix failed: ' + err); }
}

async function terminalAttach() {
    const sessions = await aiRequest('ollamadev/terminal', { action: 'list' }).catch(() => '[]');
    let sessionList: string[] = [];
    try { sessionList = JSON.parse(sessions); } catch {}
    
    if (sessionList.length === 0) {
        vscode.window.showInformationMessage('No active terminal sessions. Run "ollamadev terminal" first.');
        return;
    }
    
    const selected = await vscode.window.showQuickPick(sessionList.length > 0 ? sessionList : ['default'], {
        placeHolder: 'Select terminal session to attach'
    });
    
    if (selected) {
        const terminal = vscode.window.createTerminal({ name: `OllamaDev: ${selected}` });
        terminal.show();
        terminal.sendText(`ollamadev terminal attach ${selected}`);
    }
}

async function terminalList() {
    try {
        const result = await aiRequest('ollamadev/terminal', { action: 'list' });
        let sessionList: string[] = [];
        try { sessionList = JSON.parse(result); } catch { sessionList = result.split('\n').filter((s: string) => s.trim()); }
        
        if (sessionList.length === 0) {
            vscode.window.showInformationMessage('No active terminal sessions');
            return;
        }
        
        const doc = await vscode.workspace.openTextDocument({ content: `# Terminal Sessions\n\n${sessionList.join('\n')}`, language: 'markdown' });
        await vscode.window.showTextDocument(doc, vscode.ViewColumn.One);
    } catch (err) { vscode.window.showErrorMessage('List failed: ' + err); }
}

const WORKING_MODELS = [
    'llama3.2:latest', 'llama3.2:3b', 'llama3.1:8b', 'codestral', 'deepseek-r1:32b',
    'deepseek-coder:33b', 'gemma4:26b', 'gemma4:31b', 'qwen3.5:35b', 'command-r',
    'wizardcoder', 'codeqwen', 'starcoder', 'smollm2', 'devstral-small-2:24b',
    'olmo-3.1', 'nemotron3:33b', 'aya:35b', 'glm-4.7-flash', 'lfm2', 'gpt-oss'
];

async function modelSelect() {
    const quickPick = await vscode.window.showQuickPick(WORKING_MODELS, {
        placeHolder: 'Select Ollama model for OllamaDev'
    });
    
    if (quickPick) {
        const config = vscode.workspace.getConfiguration('ollamadev');
        await config.update('model', quickPick, true);
        vscode.window.showInformationMessage(`OllamaDev model: ${quickPick}`);
    }
}

export function activate(context: vscode.ExtensionContext) {
    createStatusBar();
    
    const provider = new InlineCompletionProvider();
    context.subscriptions.push(vscode.languages.registerInlineCompletionItemProvider({ pattern: '**' }, provider));
    
    const config = getConfig();
    provider.enabled = config.inlineCompletionEnabled;

    context.subscriptions.push(
        vscode.commands.registerCommand('ollamadev-lsp.start', startLSPProcess),
        vscode.commands.registerCommand('ollamadev-lsp.stop', stopLSP),
        vscode.commands.registerCommand('ollamadev-lsp.restart', restartLSP),
        vscode.commands.registerCommand('ollamadev-lsp.status', showStatus),
        vscode.commands.registerCommand('ollamadev.generate', generateCode),
        vscode.commands.registerCommand('ollamadev.review', reviewCode),
        vscode.commands.registerCommand('ollamadev.ask', askAI),
        vscode.commands.registerCommand('ollamadev.inlineComplete', () => toggleInlineCompletion(provider)),
        vscode.commands.registerCommand('ollamadev.chat', () => createChatPanel(context)),
        vscode.commands.registerCommand('ollamadev.complete', getCompletion),
        vscode.commands.registerCommand('ollamadev.format', formatDocument),
        vscode.commands.registerCommand('ollamadev.quickfix', quickFix),
        vscode.commands.registerCommand('ollamadev.terminalAttach', terminalAttach),
        vscode.commands.registerCommand('ollamadev.terminalList', terminalList),
        vscode.commands.registerCommand('ollamadev.modelSelect', modelSelect)
    );

    startLSPProcess().catch(err => console.error('Activation error:', err));
}

export function deactivate() { stopLSP(); }