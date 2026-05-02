package models

import (
	"time"
)

type ModelProvider string

const (
	ProviderOllama ModelProvider = "ollama"
)

type Message struct {
	ID        string    `json:"id"`
	Role      string    `json:"role"`
	Content   string    `json:"content"`
	ToolCalls []ToolCall `json:"tool_calls,omitempty"`
	ToolUse   *ToolUse   `json:"tool_use,omitempty"`
}

type ToolCall struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Args string `json:"args"`
}

type ToolUse struct {
	Name      string `json:"name"`
	InputJSON string `json:"input_json"`
	Result    string `json:"result,omitempty"`
	IsError   bool   `json:"is_error,omitempty"`
}

type Session struct {
	ID              string    `json:"id"`
	Title           string    `json:"title"`
	Model           string    `json:"model"`
	SummaryID       string    `json:"summary_id,omitempty"`
	SummaryContent string    `json:"summary_content,omitempty"`
	CreatedAt       time.Time `json:"created_at"`
	UpdatedAt       time.Time `json:"updated_at"`
}

type FileChange struct {
	SessionID string    `json:"session_id"`
	Path      string    `json:"path"`
	Change    string    `json:"change"`
	Timestamp time.Time `json:"timestamp"`
}

type Permission struct {
	ID        string    `json:"id"`
	Tool      string    `json:"tool"`
	Command   string    `json:"command"`
	SessionID string    `json:"session_id"`
	Status    string    `json:"status"` // pending, approved, denied
	CreatedAt time.Time `json:"created_at"`
}

type Diagnostics struct {
	File  string `json:"file"`
	Diags []Diag `json:"diagnostics"`
}

type Diag struct {
	Line   int    `json:"line"`
	Col    int    `json:"col"`
	Severity string `json:"severity"`
	Message string `json:"message"`
}