package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

type ToolResult struct {
	Content string
	IsError bool
}

type Tool interface {
	Name() string
	Description() string
	Parameters() map[string]Parameter
	RequiresPermission() bool
	Run(ctx context.Context, params map[string]interface{}) ToolResult
}

type Parameter struct {
	Type        string `json:"type"`
	Description string `json:"description"`
	Required    bool   `json:"required"`
}

type ViewTool struct{}

func (t *ViewTool) Name() string   { return "view" }
func (t *ViewTool) Description() string { return "View file contents with line numbers" }
func (t *ViewTool) RequiresPermission() bool { return false }

func (t *ViewTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"file_path": {Type: "string", Description: "Path to file to view", Required: true},
		"offset":    {Type: "number", Description: "Line offset to start from", Required: false},
		"limit":     {Type: "number", Description: "Max lines to show", Required: false},
	}
}

func (t *ViewTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	filePath, ok := params["file_path"].(string)
	if !ok {
		return ToolResult{Content: "missing file_path", IsError: true}
	}

	data, err := os.ReadFile(filePath)
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error reading file: %v", err), IsError: true}
	}

	lines := strings.Split(string(data), "\n")

	offset := 0
	if o, ok := params["offset"].(float64); ok {
		offset = int(o)
	}
	limit := len(lines)
	if l, ok := params["limit"].(float64); ok && l > 0 {
		limit = int(l)
	}

	if offset >= len(lines) {
		return ToolResult{Content: "offset beyond file length", IsError: true}
	}

	end := offset + limit
	if end > len(lines) {
		end = len(lines)
	}

	var result strings.Builder
	for i := offset; i < end; i++ {
		result.WriteString(fmt.Sprintf("%4d  %s\n", i+1, lines[i]))
	}
	return ToolResult{Content: result.String(), IsError: false}
}

type WriteTool struct{}

func (t *WriteTool) Name() string   { return "write" }
func (t *WriteTool) Description() string { return "Write content to a file (creates or overwrites)" }
func (t *WriteTool) RequiresPermission() bool { return true }

func (t *WriteTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"file_path": {Type: "string", Description: "Path to file to write", Required: true},
		"content":   {Type: "string", Description: "Content to write", Required: true},
	}
}

func (t *WriteTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path, ok := params["file_path"].(string)
	if !ok {
		return ToolResult{Content: "missing file_path", IsError: true}
	}
	content, ok := params["content"].(string)
	if !ok {
		return ToolResult{Content: "missing content", IsError: true}
	}

	dir := filepath.Dir(path)
	if dir != "" {
		os.MkdirAll(dir, 0755)
	}

	if err := os.WriteFile(path, []byte(content), 0644); err != nil {
		return ToolResult{Content: fmt.Sprintf("Error writing file: %v", err), IsError: true}
	}
	return ToolResult{Content: fmt.Sprintf("Written to %s", path), IsError: false}
}

type EditTool struct{}

func (t *EditTool) Name() string   { return "edit" }
func (t *EditTool) Description() string { return "Edit file content by replacing oldString with newString" }
func (t *EditTool) RequiresPermission() bool { return true }

func (t *EditTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"file_path":  {Type: "string", Description: "Path to file", Required: true},
		"old_string": {Type: "string", Description: "Text to find", Required: true},
		"new_string": {Type: "string", Description: "Replacement text", Required: true},
	}
}

func (t *EditTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path, ok := params["file_path"].(string)
	if !ok {
		return ToolResult{Content: "missing file_path", IsError: true}
	}
	oldStr, ok := params["old_string"].(string)
	if !ok {
		return ToolResult{Content: "missing old_string", IsError: true}
	}
	newStr, ok := params["new_string"].(string)
	if !ok {
		return ToolResult{Content: "missing new_string", IsError: true}
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error reading file: %v", err), IsError: true}
	}

	content := string(data)
	if !strings.Contains(content, oldStr) {
		return ToolResult{Content: "old_string not found in file", IsError: true}
	}

	content = strings.Replace(content, oldStr, newStr, 1)
	if err := os.WriteFile(path, []byte(content), 0644); err != nil {
		return ToolResult{Content: fmt.Sprintf("Error writing file: %v", err), IsError: true}
	}
	return ToolResult{Content: fmt.Sprintf("Edited %s", path), IsError: false}
}

type GlobTool struct{}

func (t *GlobTool) Name() string   { return "glob" }
func (t *GlobTool) Description() string { return "Find files matching a pattern" }
func (t *GlobTool) RequiresPermission() bool { return false }

func (t *GlobTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"pattern": {Type: "string", Description: "Glob pattern (e.g., **/*.go)", Required: true},
		"path":   {Type: "string", Description: "Base path to search", Required: false},
	}
}

func (t *GlobTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	pattern, ok := params["pattern"].(string)
	if !ok {
		return ToolResult{Content: "missing pattern", IsError: true}
	}

	basePath := "."
	if path, ok := params["path"].(string); ok && path != "" {
		basePath = path
	}

	if !strings.Contains(pattern, "*") {
		pattern = "**/*" + pattern
	}

	pattern = filepath.Join(basePath, pattern)
	matches, err := filepath.Glob(pattern)
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error: %v", err), IsError: true}
	}

	if len(matches) == 0 {
		return ToolResult{Content: "No files found", IsError: false}
	}
	return ToolResult{Content: strings.Join(matches, "\n"), IsError: false}
}

type GrepTool struct{}

func (t *GrepTool) Name() string   { return "grep" }
func (t *GrepTool) Description() string { return "Search file contents with regex" }
func (t *GrepTool) RequiresPermission() bool { return false }

func (t *GrepTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"pattern": {Type: "string", Description: "Regex pattern to search", Required: true},
		"path":    {Type: "string", Description: "Directory or file to search", Required: false},
		"include": {Type: "string", Description: "File pattern filter (e.g., *.go)", Required: false},
	}
}

func (t *GrepTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	pattern, ok := params["pattern"].(string)
	if !ok {
		return ToolResult{Content: "missing pattern", IsError: true}
	}
	path := "."
	if p, ok := params["path"].(string); ok {
		path = p
	}

	args := []string{"-rn", "--color=never", pattern, path}
	if include, ok := params["include"].(string); ok && include != "" {
		args = []string{"-rn", "--color=never", "--include", include, pattern, path}
	}

	cmd := exec.Command("grep", args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: string(out), IsError: err != nil}
	}
	return ToolResult{Content: string(out), IsError: false}
}

type LsTool struct{}

func (t *LsTool) Name() string   { return "ls" }
func (t *LsTool) Description() string { return "List directory contents" }
func (t *LsTool) RequiresPermission() bool { return false }

func (t *LsTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"path":   {Type: "string", Description: "Directory to list", Required: false},
		"ignore": {Type: "array", Description: "Patterns to ignore", Required: false},
	}
}

func (t *LsTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path := "."
	if p, ok := params["path"].(string); ok && p != "" {
		path = p
	}

	cmd := exec.Command("ls", "-la", path)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error: %v", err), IsError: true}
	}
	return ToolResult{Content: string(out), IsError: false}
}

type BashTool struct {
	readonlyCmds map[string]bool
	allowedCmds  map[string]bool
	permissionTool
}

type permissionTool struct{}

func (p *permissionTool) Name() string { return "permission" }
func (p *permissionTool) RequiresPermission() bool { return false }

func NewBashTool() *BashTool {
	return &BashTool{
		readonlyCmds: map[string]bool{
			"ls": true, "pwd": true, "cat": true, "head": true, "tail": true,
			"grep": true, "find": true, "git": true, "echo": true, "wc": true,
			"sort": true, "uniq": true, "awk": true, "sed": true, "cut": true,
			"tr": true, "tee": true, "xargs": true, "which": true, "type": true,
			"file": true, "stat": true, "diff": true, "tree": true, "mkdir": true,
			"touch": true, "cp": true, "mv": true,
		},
		allowedCmds: map[string]bool{
			"ls": true, "pwd": true, "cat": true, "head": true, "tail": true,
			"grep": true, "find": true, "git": true, "echo": true, "wc": true,
			"sort": true, "uniq": true, "awk": true, "sed": true, "cut": true,
			"tr": true, "tee": true, "xargs": true, "which": true, "type": true,
			"file": true, "stat": true, "diff": true, "tree": true, "mkdir": true,
			"touch": true, "cp": true, "mv": true, "rm": true, "cd": true,
		},
	}
}

func (t *BashTool) Name() string   { return "bash" }
func (t *BashTool) Description() string { return "Execute shell commands" }
func (t *BashTool) RequiresPermission() bool { return true }

func (t *BashTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"command": {Type: "string", Description: "Shell command to execute", Required: true},
	}
}

func (t *BashTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	cmd, ok := params["command"].(string)
	if !ok {
		return ToolResult{Content: "missing command", IsError: true}
	}

	parts := strings.Fields(cmd)
	if len(parts) == 0 {
		return ToolResult{Content: "empty command", IsError: true}
	}

	if t.readonlyCmds[parts[0]] {
		return t.execCmd(cmd)
	}

	if !t.allowedCmds[parts[0]] {
		return ToolResult{Content: fmt.Sprintf("command not allowed: %s", parts[0]), IsError: true}
	}

	banned := []string{"curl", "wget", "chmod", "sudo", "rm -rf", "mkfs", ":()", "dd if="}
	for _, b := range banned {
		if strings.Contains(cmd, b) {
			return ToolResult{Content: fmt.Sprintf("dangerous command blocked: %s", b), IsError: true}
		}
	}

	return t.execCmd(cmd)
}

func (t *BashTool) execCmd(cmd string) ToolResult {
	execmd := exec.Command("bash", "-c", cmd)
	execmd.Dir, _ = os.Getwd()
	out, err := execmd.CombinedOutput()

	if len(out) > 30000 {
		out = out[:30000]
	}

	if err != nil {
		return ToolResult{Content: string(out), IsError: true}
	}
	return ToolResult{Content: string(out), IsError: false}
}

type FetchTool struct{}

func (t *FetchTool) Name() string   { return "fetch" }
func (t *FetchTool) Description() string { return "Fetch content from a URL" }
func (t *FetchTool) RequiresPermission() bool { return true }

func (t *FetchTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"url":     {Type: "string", Description: "URL to fetch", Required: true},
		"timeout": {Type: "number", Description: "Timeout in seconds", Required: false},
	}
}

func (t *FetchTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	url, ok := params["url"].(string)
	if !ok {
		return ToolResult{Content: "missing url", IsError: true}
	}

	args := []string{"-fsSL", url}
	if timeout, ok := params["timeout"].(float64); ok {
		args = append(args, "-m", fmt.Sprintf("%d", int(timeout)))
	}

	cmd := exec.Command("curl", args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error fetching: %v\n%s", err, string(out)), IsError: true}
	}
	return ToolResult{Content: string(out), IsError: false}
}

type DiagnosticsTool struct{}

func (t *DiagnosticsTool) Name() string   { return "diagnostics" }
func (t *DiagnosticsTool) Description() string { return "Get LSP diagnostics for a file" }
func (t *DiagnosticsTool) RequiresPermission() bool { return false }

func (t *DiagnosticsTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"file_path": {Type: "string", Description: "Path to file", Required: false},
	}
}

func (t *DiagnosticsTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path, _ := params["file_path"].(string)
	if path == "" {
		return ToolResult{Content: "No file specified", IsError: true}
	}

	cmd := exec.Command("go", "vet", path)
	out, _ := cmd.CombinedOutput()
	return ToolResult{Content: string(out), IsError: false}
}

type PatchTool struct{}

func (t *PatchTool) Name() string   { return "patch" }
func (t *PatchTool) Description() string { return "Apply a patch to a file" }
func (t *PatchTool) RequiresPermission() bool { return true }

func (t *PatchTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"file_path": {Type: "string", Description: "Path to file", Required: true},
		"diff":      {Type: "string", Description: "Unified diff content", Required: true},
	}
}

func (t *PatchTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path, ok := params["file_path"].(string)
	if !ok {
		return ToolResult{Content: "missing file_path", IsError: true}
	}
	diff, ok := params["diff"].(string)
	if !ok {
		return ToolResult{Content: "missing diff", IsError: true}
	}

	tmpFile, err := os.CreateTemp("", "patch-*.diff")
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error creating temp file: %v", err), IsError: true}
	}
	defer os.Remove(tmpFile.Name())

	if _, err := tmpFile.WriteString(diff); err != nil {
		return ToolResult{Content: fmt.Sprintf("Error writing diff: %v", err), IsError: true}
	}
	tmpFile.Close()

	cmd := exec.Command("patch", "-p1", "-i", tmpFile.Name())
	out, err := cmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Patch failed: %v\n%s", err, string(out)), IsError: true}
	}
	return ToolResult{Content: fmt.Sprintf("Patched %s", path), IsError: false}
}

type AgentTool struct{}

func (t *AgentTool) Name() string   { return "agent" }
func (t *AgentTool) Description() string { return "Run a sub-agent for complex tasks" }
func (t *AgentTool) RequiresPermission() bool { return false }

func (t *AgentTool) Parameters() map[string]Parameter {
	return map[string]Parameter{
		"prompt": {Type: "string", Description: "Task description for sub-agent", Required: true},
	}
}

func (t *AgentTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	prompt, ok := params["prompt"].(string)
	if !ok {
		return ToolResult{Content: "missing prompt", IsError: true}
	}
	return ToolResult{Content: fmt.Sprintf("Sub-agent requested: %s\n(Sub-agents not yet implemented)", prompt), IsError: false}
}

func AllTools() []Tool {
	return []Tool{
		&ViewTool{},
		&WriteTool{},
		&EditTool{},
		&GlobTool{},
		&GrepTool{},
		&LsTool{},
		NewBashTool(),
		&FetchTool{},
		&DiagnosticsTool{},
		&PatchTool{},
		&AgentTool{},
	}
}

func FindTool(name string) Tool {
	for _, t := range AllTools() {
		if t.Name() == name {
			return t
		}
	}
	return nil
}

func ToolToJSON(t Tool) string {
	params, _ := json.Marshal(t.Parameters())
	return fmt.Sprintf(`{"name":"%s","description":"%s","parameters":%s}`, t.Name(), t.Description(), string(params))
}