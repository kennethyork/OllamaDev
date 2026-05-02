package tui

import (
	"github.com/charmbracelet/lipgloss"
)

var (
	Primary   = lipgloss.Color("#007ACC")
	Success   = lipgloss.Color("#28A745")
	Warning   = lipgloss.Color("#FFC107")
	Error     = lipgloss.Color("#DC3545")
	Info      = lipgloss.Color("#17A2B8")

	bgDark    = lipgloss.Color("#1E1E1E")
	bgSurface = lipgloss.Color("#252526")
	bgHover   = lipgloss.Color("#2D2D30")
	bgSelect  = lipgloss.Color("#094771")

	textPrimary = lipgloss.Color("#D4D4D4")
	textDim     = lipgloss.Color("#808080")
	textBright  = lipgloss.Color("#FFFFFF")

	border     = lipgloss.Color("#3C3C3C")
	borderFocus = lipgloss.Color("#007ACC")

	roleUser  = lipgloss.Color("#4FC1FF")
	roleAsst  = lipgloss.Color("#4EC9B0")
	roleSys   = lipgloss.Color("#CE9178")
	roleTool  = lipgloss.Color("#DCDCAA")
)

var Styles = struct {
	App           lipgloss.Style
	Title         lipgloss.Style
	Surface       lipgloss.Style
	Input         lipgloss.Style
	StatusBar     lipgloss.Style
	MessageUser   lipgloss.Style
	MessageAsst   lipgloss.Style
	MessageTool   lipgloss.Style
	ToolCall      lipgloss.Style
	Dialog        lipgloss.Style
	DialogTitle   lipgloss.Style
	DialogButton  lipgloss.Style
	DialogButtonActive lipgloss.Style
	Help          lipgloss.Style
	HelpKey       lipgloss.Style
	ListItem      lipgloss.Style
	ListItemSelected lipgloss.Style
	Permission    lipgloss.Style
	PermissionIcon lipgloss.Style
}{
	App: lipgloss.NewStyle().
		Background(bgDark).
		Foreground(textPrimary),

	Title: lipgloss.NewStyle().
		Foreground(Primary).
		Bold(true),

	Surface: lipgloss.NewStyle().
		Background(bgSurface),

	Input: lipgloss.NewStyle().
		Background(bgSurface).
		Foreground(textPrimary).
		Border(border).
		BorderForeground(borderFocus),

	StatusBar: lipgloss.NewStyle().
		Background(bgSurface).
		Foreground(textDim).
		Padding(0, 1),

	MessageUser: lipgloss.NewStyle().
		Foreground(roleUser),

	MessageAsst: lipgloss.NewStyle().
		Foreground(roleAsst),

	MessageTool: lipgloss.NewStyle().
		Foreground(roleTool).
		Background(bgSurface),

	ToolCall: lipgloss.NewStyle().
		Foreground(Warning).
		Background(bgSurface).
		Padding(0, 1),

	Dialog: lipgloss.NewStyle().
		Background(bgSurface).
		Border(border).
		BorderForeground(borderFocus).
		Width(60).
		Padding(1),

	DialogTitle: lipgloss.NewStyle().
		Foreground(textBright).
		Bold(true),

	DialogButton: lipgloss.NewStyle().
		Foreground(textDim).
		Padding(0, 1),

	DialogButtonActive: lipgloss.NewStyle().
		Foreground(Primary).
		Background(bgSelect).
		Padding(0, 1),

	Help: lipgloss.NewStyle().
		Background(bgSurface).
		Foreground(textDim).
		Width(50),

	HelpKey: lipgloss.NewStyle().
		Foreground(textBright),

	ListItem: lipgloss.NewStyle().
		Foreground(textPrimary),

	ListItemSelected: lipgloss.NewStyle().
		Foreground(textBright).
		Background(bgSelect),

	Permission: lipgloss.NewStyle().
		Background(bgSurface).
		Foreground(Warning).
		Width(70).
		Padding(1),

	PermissionIcon: lipgloss.NewStyle().
		Foreground(Warning),
}

func RenderMarkdown(text string) string {
	return text
}