package agent

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/google/uuid"
	"ollamadev/internal/client"
	"ollamadev/internal/config"
	"ollamadev/internal/db"
	"ollamadev/internal/llm/tools"
	"ollamadev/internal/models"
	"ollamadev/internal/permission"
)

type Agent struct {
	client     *client.OllamaClient
	cfg        *config.Config
	db         *db.DB
	perm       *permission.Service
	model      string
	tools      map[string]tools.Tool
	systemPrompts map[string]string
}

func New(cfg *config.Config, db *db.DB, perm *permission.Service) *Agent {
	toolMap := make(map[string]tools.Tool)
	for _, t := range tools.AllTools() {
		toolMap[t.Name()] = t
	}

	return &Agent{
		client:        client.New(cfg),
		cfg:           cfg,
		db:            db,
		perm:          perm,
		model:         cfg.Ollama.DefaultModel,
		tools:         toolMap,
		systemPrompts: loadPrompts(),
	}
}

func loadPrompts() map[string]string {
	return map[string]string{
		"coder": `You are OllamaDev, an AI coding assistant running locally via Ollama.

You have access to tools to help with coding tasks:
- view: Read file contents with line numbers
- write: Create or overwrite files
- edit: Replace text in files (use old_string/new_string)
- glob: Find files matching patterns
- grep: Search file contents with regex
- ls: List directory contents
- bash: Execute shell commands (read-only commands allowed freely)
- fetch: Fetch content from URLs
- diagnostics: Get LSP diagnostics
- patch: Apply diff patches

Guidelines:
- Be helpful and precise
- Use tools when needed to accomplish tasks
- Ask for confirmation before destructive actions
- Prefer showing code over explaining
- When you need to run a non-read-only command, request permission`,
		"summarizer": `You are a summarization assistant. Your task is to create a concise summary of the conversation history. Focus on:
- Key decisions made
- Important code changes
- Current task context
- Any pending work

Keep the summary under 500 words.`,
	}
}

func (a *Agent) SetModel(model string) {
	a.model = model
}

func (a *Agent) GetModel() string {
	return a.model
}

func (a *Agent) ListModels() ([]client.ModelInfo, error) {
	return a.client.ListModels()
}

func (a *Agent) CheckConnection() error {
	return a.client.CheckConnection()
}

func (a *Agent) Run(ctx context.Context, sessionID string, messages []models.Message, handler func(models.Message)) error {
	systemPrompt := a.systemPrompts["coder"]

	var clientMsgs []client.Message
	clientMsgs = append(clientMsgs, client.Message{Role: "system", Content: systemPrompt})

	for _, m := range messages {
		clientMsgs = append(clientMsgs, client.Message{Role: m.Role, Content: m.Content})
	}

	req := client.ChatRequest{
		Model:       a.model,
		Messages:    clientMsgs,
		Temperature: a.cfg.Agents["coder"].Temperature,
		MaxTokens:   a.cfg.Agents["coder"].MaxTokens,
	}

	return a.client.Chat(req, func(msg client.Message) {
		if handler != nil {
			handler(models.Message{
				ID:      uuid.New().String(),
				Role:    msg.Role,
				Content: msg.Content,
			})
		}
	})
}

func (a *Agent) Summarize(ctx context.Context, messages []models.Message) (string, error) {
	if len(messages) == 0 {
		return "", nil
	}

	var content strings.Builder
	for _, m := range messages {
		content.WriteString(fmt.Sprintf("%s: %s\n", m.Role, m.Content))
	}

	summarizePrompt := a.systemPrompts["summarizer"] + "\n\nConversation:\n" + content.String()

	req := client.GenerateRequest{
		Model:       a.model,
		Prompt:      summarizePrompt,
		Temperature: 0.3,
		MaxTokens:   500,
	}

	var summary strings.Builder
	err := a.client.Generate(req, func(s string) {
		summary.WriteString(s)
	})
	if err != nil {
		return "", err
	}

	return strings.TrimSpace(summary.String()), nil
}

func (a *Agent) ParseAndExecuteTool(ctx context.Context, content string) ([]models.Message, error) {
	var results []models.Message

	toolCalls := parseToolCalls(content)
	for _, tc := range toolCalls {
		tool := a.tools[tc.Name]
		if tool == nil {
			results = append(results, models.Message{
				ID:      uuid.New().String(),
				Role:    "tool",
				Content: fmt.Sprintf("Error: tool '%s' not found", tc.Name),
			})
			continue
		}

		if tool.RequiresPermission() && !a.perm.IsAllowed(tc.Name, tc.Args) {
			approved, err := a.perm.Request(tc.Name, tc.Args, "")
			if err != nil || !approved {
				results = append(results, models.Message{
					ID:      uuid.New().String(),
					Role:    "tool",
					Content: fmt.Sprintf("Permission denied for tool '%s'", tc.Name),
				})
				continue
			}
		}

		params := parseToolParams(tc.Args)
		result := tool.Run(ctx, params)

		results = append(results, models.Message{
			ID:      uuid.New().String(),
			Role:    "tool",
			Content: result.Content,
		})
	}

	return results, nil
}

func parseToolCalls(content string) []models.ToolCall {
	var calls []models.ToolCall

	lines := strings.Split(content, "\n")
	inCall := false
	var current models.ToolCall
	var args strings.Builder

	for _, line := range lines {
		trimmed := strings.TrimSpace(line)

		if strings.HasPrefix(trimmed, "<tool_call>") || strings.HasPrefix(trimmed, "```tool_call") {
			inCall = true
			continue
		}
		if strings.HasSuffix(trimmed, "</tool_call>") || strings.HasPrefix(trimmed, "```") {
			if inCall && current.Name != "" {
				current.Args = args.String()
				calls = append(calls, current)
			}
			inCall = false
			current = models.ToolCall{ID: uuid.New().String()}
			args.Reset()
			continue
		}

		if inCall {
			if strings.HasPrefix(trimmed, "name:") {
				current.Name = strings.TrimSpace(strings.TrimPrefix(trimmed, "name:"))
			} else if strings.HasPrefix(trimmed, "params:") || strings.HasPrefix(trimmed, "args:") {
				args.WriteString(strings.TrimSpace(strings.TrimPrefix(trimmed, "params:")))
				args.WriteString(strings.TrimSpace(strings.TrimPrefix(trimmed, "args:")))
			} else if args.Len() > 0 {
				args.WriteString(" " + trimmed)
			} else {
				args.WriteString(trimmed)
			}
		}
	}

	return calls
}

func parseToolParams(argsStr string) map[string]interface{} {
	params := make(map[string]interface{})

	argsStr = strings.TrimSpace(argsStr)
	if argsStr == "" {
		return params
	}

	if strings.HasPrefix(argsStr, "{") && strings.HasSuffix(argsStr, "}") {
		json.Unmarshal([]byte(argsStr), &params)
		return params
	}

	pairs := strings.Split(argsStr, ",")
	for _, pair := range pairs {
		kv := strings.SplitN(strings.TrimSpace(pair), "=", 2)
		if len(kv) == 2 {
			key := strings.TrimSpace(kv[0])
			val := strings.TrimSpace(kv[1])
			val = strings.Trim(val, "\"' ")
			params[key] = val
		}
	}

	return params
}

func CountTokens(messages []models.Message) int {
	total := 0
	for _, m := range messages {
		total += len(m.Content) / 4
	}
	return total
}