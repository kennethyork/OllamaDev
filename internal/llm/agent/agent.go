package agent

import (
	"context"
	"strings"

	"ollamadev/internal/client"
	"ollamadev/internal/config"
	"ollamadev/internal/llm/tools"
	"ollamadev/internal/models"
)

type Agent struct {
	client  *client.OllamaClient
	config  *config.Agent
	model   string
	tools   []tools.Tool
	handler func(models.Message)
}

func New(cfg *config.Config, handler func(models.Message)) *Agent {
	return &Agent{
		client: client.New(cfg),
		config: &cfg.Agents.Coder,
		model:  cfg.Ollama.DefaultModel,
		tools:  tools.AllTools(),
		handler: handler,
	}
}

func (a *Agent) Run(ctx context.Context, messages []models.Message) error {
	systemPrompt := `You are OllamaDev, an AI coding assistant running locally via Ollama.

You have access to tools: view, write, edit, glob, grep, ls, bash, fetch.

Guidelines:
- Be helpful and precise
- Use tools when needed to accomplish tasks
- Show code when relevant
- Ask for confirmation before destructive actions`

	chatReq := client.ChatRequest{
		Model:       a.model,
		Messages:    append([]client.Message{{Role: "system", Content: systemPrompt}}, messagesToClient(messages)...),
		Temperature: a.config.Temperature,
		MaxTokens:   a.config.MaxTokens,
	}

	return a.client.Chat(chatReq, func(msg client.Message) {
		if a.handler != nil {
			a.handler(models.Message{
				Role:    msg.Role,
				Content: msg.Content,
			})
		}
	})
}

func (a *Agent) ExecuteTool(ctx context.Context, toolName string, params map[string]interface{}) tools.ToolResult {
	tool := tools.FindTool(toolName)
	if tool == nil {
		return tools.ToolResult{Content: "tool not found: " + toolName, IsError: true}
	}
	return tool.Run(ctx, params)
}

func (a *Agent) SetModel(model string) {
	a.model = model
}

func (a *Agent) ListModels() ([]client.ModelInfo, error) {
	return a.client.ListModels()
}

func (a *Agent) CheckConnection() error {
	return a.client.CheckConnection()
}

func messagesToClient(msgs []models.Message) []client.Message {
	result := make([]client.Message, len(msgs))
	for i, m := range msgs {
		result[i] = client.Message{Role: m.Role, Content: m.Content}
	}
	return result
}

func ParseToolCalls(content string) []models.ToolCall {
	var calls []models.ToolCall
	lines := strings.Split(content, "\n")
	inTool := false
	var current map[string]interface{}
	var currentName, currentInput string

	for _, line := range lines {
		if strings.Contains(line, "<tool_call>") {
			inTool = true
			current = make(map[string]interface{})
			currentInput = ""
			continue
		}
		if strings.Contains(line, "</tool_call>") {
			inTool = false
			if currentName != "" {
				calls = append(calls, models.ToolCall{
					Name:     currentName,
					Input:    current,
					InputStr: currentInput,
				})
			}
			currentName = ""
			currentInput = ""
			continue
		}
		if inTool {
			if strings.HasPrefix(line, "name:") {
				currentName = strings.TrimSpace(strings.TrimPrefix(line, "name:"))
			} else if strings.HasPrefix(line, "params:") {
				currentInput = strings.TrimSpace(strings.TrimPrefix(line, "params:"))
			}
		}
	}
	return calls
}