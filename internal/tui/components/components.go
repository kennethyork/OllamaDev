package tui

import (
	"ollamadev/internal/models"
)

type MessageItem struct {
	ID      string
	Role    string
	Content string
	ToolCall *models.ToolCall
}

type SessionItem struct {
	ID    string
	Title string
	Model string
}