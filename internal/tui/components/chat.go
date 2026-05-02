package tui

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/lipgloss"
)

func RenderMessage(msg MessageItem) string {
	var builder strings.Builder

	roleColor := lipgloss.Color("#D4D4D4")
	if msg.Role == "user" {
		roleColor = lipgloss.Color("#4FC1FF")
	} else if msg.Role == "assistant" {
		roleColor = lipgloss.Color("#4EC9B0")
	}

	builder.WriteString(lipgloss.NewStyle().
		Foreground(roleColor).
		Bold(true).
		Render(fmt.Sprintf("[%s]", msg.Role)))
	builder.WriteString("\n")

	if msg.ToolCall != nil {
		builder.WriteString(lipgloss.NewStyle().
			Foreground(lipgloss.Color("#FFC107")).
			Background(lipgloss.Color("#252526")).
			Render(fmt.Sprintf("Tool: %s", msg.ToolCall.Name)))
		builder.WriteString("\n")
	}

	builder.WriteString(msg.Content)

	return builder.String()
}

func RenderMarkdown(text string) string {
	return text
}

func TruncateWidth(s string, maxWidth int) string {
	if len(s) > maxWidth {
		return s[:maxWidth-3] + "..."
	}
	return s
}