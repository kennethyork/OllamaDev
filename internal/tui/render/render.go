package tui

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/lipgloss"
)

type MessageItem struct {
	ID        string
	Role      string
	Content   string
	ToolName  string
	ToolResult string
	Timestamp int64
	Streaming bool
}

type SessionItem struct {
	ID    string
	Title string
	Model string
	Time  string
}

func RenderMessage(msg MessageItem) string {
	var b strings.Builder

	roleColor := msgColor(msg.Role)

	b.WriteString(lipgloss.NewStyle().
		Foreground(roleColor).
		Bold(true).
		Render(fmt.Sprintf("[%s]", msg.Role)))
	b.WriteString("\n")

	if msg.ToolName != "" {
		b.WriteString(lipgloss.NewStyle().
			Foreground(Warning).
			Render(fmt.Sprintf("→ Tool: %s", msg.ToolName)))
		b.WriteString("\n\n")
		b.WriteString(lipgloss.NewStyle().
			Foreground(textDim).
			Background(bgSurface).
			Render(msg.ToolResult))
	} else {
		b.WriteString(msg.Content)
	}

	return b.String()
}

func msgColor(role string) lipgloss.Color {
	switch role {
	case "user":
		return roleUser
	case "assistant":
		return roleAsst
	case "system":
		return roleSys
	case "tool":
		return roleTool
	default:
		return textPrimary
	}
}

func RenderToolCall(name, args string) string {
	return lipgloss.NewStyle().
		Foreground(Warning).
		Background(bgSurface).
		Render(fmt.Sprintf("⬡ %s(%s)", name, args))
}

func RenderPermissionDialog(tool, command string) string {
	var b strings.Builder
	b.WriteString(Styles.Dialog.Render(
		lipgloss.Join(
			lipgloss.Vertical,
			Styles.DialogTitle.Render("⚠ Permission Required"),
			"",
			lipgloss.NewStyle().Width(50).Render(fmt.Sprintf("Tool: %s", tool)),
			lipgloss.NewStyle().Width(50).Render(fmt.Sprintf("Command: %s", command)),
			"",
			lipgloss.NewStyle().Foreground(textDim).Render("Allow this command?"),
			"",
			lipgloss.Join(lipgloss.Horizontal,
				Styles.DialogButtonActive.Render("[a] Allow"),
				lipgloss.NewStyle().Width(3).Render(""),
				Styles.DialogButton.Render("[d] Deny"),
				lipgloss.NewStyle().Width(3).Render(""),
				Styles.DialogButton.Render("[A] Allow all"),
			),
		),
	))
	return b.String()
}

func RenderSessionList(sessions []SessionItem, selected int) string {
	var b strings.Builder
	b.WriteString("Sessions\n")
	b.WriteString(strings.Repeat("─", 40))
	b.WriteString("\n")

	for i, s := range sessions {
		prefix := "  "
		if i == selected {
			prefix = "→ "
		}
		style := Styles.ListItem
		if i == selected {
			style = Styles.ListItemSelected
		}
		b.WriteString(style.Render(fmt.Sprintf("%s%s (%s)", prefix, s.Title, s.Time)))
		b.WriteString("\n")
	}
	return b.String()
}

func RenderCommandList(commands []string, selected int) string {
	var b strings.Builder
	b.WriteString(Styles.DialogTitle.Render("Commands"))
	b.WriteString("\n")
	b.WriteString(strings.Repeat("─", 40))
	b.WriteString("\n")

	for i, c := range commands {
		prefix := "  "
		if i == selected {
			prefix = "→ "
		}
		style := Styles.ListItem
		if i == selected {
			style = Styles.ListItemSelected
		}
		b.WriteString(style.Render(fmt.Sprintf("%s%s", prefix, c)))
		b.WriteString("\n")
	}
	return b.String()
}

func RenderHelpDialog() string {
	keys := []struct{ key, desc string }{
		{"Ctrl+C", "Quit"},
		{"Ctrl+K", "Toggle help"},
		{"Ctrl+O", "Select model"},
		{"Ctrl+A", "Switch session"},
		{"Ctrl+L", "View logs"},
		{"Ctrl+X", "Cancel"},
		{"Ctrl+N", "New session"},
		{"↑/↓", "Navigate"},
		{"Enter", "Select"},
		{"Esc", "Close"},
	}

	var b strings.Builder
	b.WriteString(Styles.Help.Render(
		lipgloss.NewStyle().Bold(true).Render("Keyboard Shortcuts"),
	))

	for _, k := range keys {
		b.WriteString(fmt.Sprintf("\n  %-10s %s",
			lipgloss.NewStyle().Foreground(Primary).Render(k.key),
			k.desc))
	}
	return b.String()
}

func RenderLogs(content string) string {
	return lipgloss.NewStyle().
		Background(bgDark).
		Foreground(textDim).
		Render(content)
}

func RenderModelSelect(models []string, selected int) string {
	var b strings.Builder
	b.WriteString(Styles.DialogTitle.Render("Select Model"))
	b.WriteString("\n")
	b.WriteString(strings.Repeat("─", 40))
	b.WriteString("\n")

	for i, m := range models {
		prefix := "  "
		if i == selected {
			prefix = "→ "
		}
		style := Styles.ListItem
		if i == selected {
			style = Styles.ListItemSelected
		}
		b.WriteString(style.Render(fmt.Sprintf("%s%s", prefix, m)))
		b.WriteString("\n")
	}
	return b.String()
}

func RenderStatusBar(model string, tokenCount, msgCount int) string {
	return lipgloss.NewStyle().
		Background(bgSurface).
		Foreground(textDim).
		Width(80).
		Render(fmt.Sprintf("  Model: %s | Tokens: %d | Messages: %d | Ctrl+K: Help | Ctrl+O: Models",
			model, tokenCount, msgCount))
}

func RenderFileChanges(changes []string) string {
	var b strings.Builder
	b.WriteString(lipgloss.NewStyle().Foreground(textDim).Render("File Changes:"))
	b.WriteString("\n")
	for _, c := range changes {
		b.WriteString(lipgloss.NewStyle().Foreground(Warning).Render(" ● "))
		b.WriteString(c)
		b.WriteString("\n")
	}
	return b.String()
}