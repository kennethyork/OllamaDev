package tui

import (
	"github.com/charmbracelet/lipgloss"
)

var (
	primaryColor = lipgloss.Color("#007ACC")
	bgColor      = lipgloss.Color("#1E1E1E")
	surfaceColor = lipgloss.Color("#252526")
	textColor    = lipgloss.Color("#D4D4D4")
	successColor = lipgloss.Color("#28A745")
	errorColor   = lipgloss.Color("#DC3545")
	warningColor = lipgloss.Color("#FFC107")
	dimColor     = lipgloss.Color("#808080")

	Style = lipgloss.NewStyle().
		Foreground(textColor).
		Background(bgColor)

	TitleStyle = lipgloss.NewStyle().
			Foreground(primaryColor).
			Bold(true)

	MessageStyle = lipgloss.NewStyle().
			Foreground(textColor)

	ToolCallStyle = lipgloss.NewStyle().
			Foreground(warningColor).
			Background(surfaceColor)

	ErrorStyle = lipgloss.NewStyle().
			Foreground(errorColor)

	SurfaceStyle = lipgloss.NewStyle().
			Background(surfaceColor)

	StatusBarStyle = lipgloss.NewStyle().
			Background(surfaceColor).
			Foreground(dimColor)
)