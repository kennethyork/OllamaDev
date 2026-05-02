package models

type ModelProvider string

const (
	ProviderOpenAI   ModelProvider = "openai"
	ProviderAnthropic ModelProvider = "anthropic"
	ProviderOllama    ModelProvider = "ollama"
)

type Model struct {
	Name     string
	Provider ModelProvider
}

type Message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

type ToolCall struct {
	ID       string
	Name     string
	Input    map[string]interface{}
	InputStr string
}

type ToolResult struct {
	ToolCallID string `json:"tool_call_id"`
	Content    string `json:"content"`
	IsError    bool   `json:"is_error"`
}

type Session struct {
	ID         string
	Title      string
	Model      string
	Messages   []Message
	CreatedAt  int64
	UpdatedAt  int64
}

type FileChange struct {
	Path      string
	Change    string
	Timestamp int64
}