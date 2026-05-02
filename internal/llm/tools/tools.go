package tools

import (
	"context"
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
	Run(ctx context.Context, params map[string]interface{}) ToolResult
}

type ViewTool struct{}

func (t *ViewTool) Name() string   { return "view" }
func (t *ViewTool) Description() string { return "View file contents with line numbers" }

func (t *ViewTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path, ok := params["file_path"].(string)
	if !ok {
		return ToolResult{Content: "missing file_path", IsError: true}
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error reading file: %v", err), IsError: true}
	}

	lines := strings.Split(string(data), "\n")
	var result strings.Builder
	for i, line := range lines {
		result.WriteString(fmt.Sprintf("%4d  %s\n", i+1, line))
	}
	return ToolResult{Content: result.String(), IsError: false}
}

type WriteTool struct{}

func (t *WriteTool) Name() string   { return "write" }
func (t *WriteTool) Description() string { return "Write content to a file (creates or overwrites)" }

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

func (t *EditTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path, ok := params["file_path"].(string)
	if !ok {
		return ToolResult{Content: "missing file_path", IsError: true}
	}
	oldStr, ok := params["oldString"].(string)
	if !ok {
		return ToolResult{Content: "missing oldString", IsError: true}
	}
	newStr, ok := params["newString"].(string)
	if !ok {
		return ToolResult{Content: "missing newString", IsError: true}
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error reading file: %v", err), IsError: true}
	}

	content := string(data)
	if !strings.Contains(content, oldStr) {
		return ToolResult{Content: "oldString not found in file", IsError: true}
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

func (t *GlobTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	pattern, ok := params["pattern"].(string)
	if !ok {
		return ToolResult{Content: "missing pattern", IsError: true}
	}

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

func (t *GrepTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	pattern, ok := params["pattern"].(string)
	if !ok {
		return ToolResult{Content: "missing pattern", IsError: true}
	}
	path, _ := params["path"].(string)

	if path == "" {
		path = "."
	}

	cmd := exec.Command("grep", "-rn", pattern, path)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: string(out), IsError: false}
	}
	return ToolResult{Content: string(out), IsError: false}
}

type LsTool struct{}

func (t *LsTool) Name() string   { return "ls" }
func (t *LsTool) Description() string { return "List directory contents" }

func (t *LsTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	path, _ := params["path"].(string)
	if path == "" {
		path = "."
	}

	cmd := exec.Command("ls", "-la", path)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error: %v", err), IsError: true}
	}
	return ToolResult{Content: string(out), IsError: false}
}

type BashTool struct {
	allowedCmds map[string]bool
}

func NewBashTool() *BashTool {
	allowed := map[string]bool{
		"ls": true, "pwd": true, "cat": true, "head": true, "tail": true,
		"grep": true, "find": true, "git": true, "echo": true, "wc": true,
		"sort": true, "uniq": true, "awk": true, "sed": true, "cut": true,
		"tr": true, "tee": true, "xargs": true, "which": true, "type": true,
		"file": true, "stat": true, "diff": true, "tree": true, "mkdir": true,
		"touch": true, "cp": true, "mv": true, "rm": true,
	}
	return &BashTool{allowedCmds: allowed}
}

func (t *BashTool) Name() string   { return "bash" }
func (t *BashTool) Description() string { return "Execute shell commands" }

func (t *BashTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	cmd, ok := params["command"].(string)
	if !ok {
		return ToolResult{Content: "missing command", IsError: true}
	}

	parts := strings.Fields(cmd)
	if len(parts) == 0 {
		return ToolResult{Content: "empty command", IsError: true}
	}

	if !t.allowedCmds[parts[0]] {
		return ToolResult{Content: fmt.Sprintf("command not allowed: %s", parts[0]), IsError: true}
	}

	execmd := exec.Command("bash", "-c", cmd)
	execmd.Dir, _ = os.Getwd()
	out, err := execmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: string(out), IsError: true}
	}
	return ToolResult{Content: string(out), IsError: false}
}

type FetchTool struct{}

func (t *FetchTool) Name() string   { return "fetch" }
func (t *FetchTool) Description() string { return "Fetch content from a URL" }

func (t *FetchTool) Run(ctx context.Context, params map[string]interface{}) ToolResult {
	url, ok := params["url"].(string)
	if !ok {
		return ToolResult{Content: "missing url", IsError: true}
	}

	cmd := exec.Command("curl", "-s", url)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return ToolResult{Content: fmt.Sprintf("Error fetching: %v", err), IsError: true}
	}
	return ToolResult{Content: string(out), IsError: false}
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