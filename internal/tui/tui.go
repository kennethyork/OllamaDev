package tui

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/charmbracelet/bubbles/textarea"
	"github.com/charmbracelet/bubbles/viewport"
	"github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"

	"ollamadev/internal/config"
	"ollamadev/internal/llm/agent"
	"ollamadev/internal/models"
	"ollamadev/internal/tui/theme"
)

type model struct {
	cfg       *config.Config
	agent     *agent.Agent
	messages  []MessageItem
	sessions  []SessionItem
	currentSession int
	selectedModel string
	models    []string
	
	input    textarea.Model
	viewport viewport.Model
	
	page     string
	showingHelp bool
	showingModelSelect bool
	showingSessionSelect bool
	
	streaming bool
	currentResponse string
}

func New(cfg *config.Config) *model {
	input := textarea.New()
	input.Placeholder = "Type a message..."
	input.Focus()
	
	vp := viewport.New(80, 20)
	vp.SetContent("Welcome to OllamaDev! Start chatting or press ? for help.")

	m := &model{
		cfg:       cfg,
		selectedModel: cfg.Ollama.DefaultModel,
		page:      "chat",
		input:     input,
		viewport: vp,
		messages:  []MessageItem{},
	}
	
	m.agent = agent.New(cfg, m.handleAssistantMessage)
	
	return m
}

func (m *model) handleAssistantMessage(msg models.Message) {
	m.currentResponse += msg.Content
	m.updateViewport()
}

func (m *model) updateViewport() {
	var builder strings.Builder
	for _, msg := range m.messages {
		builder.WriteString(renderMessage(msg))
		builder.WriteString("\n\n")
	}
	if m.streaming && m.currentResponse != "" {
		builder.WriteString(renderMessage(MessageItem{
			Role:    "assistant",
			Content: m.currentResponse,
		}))
	}
	m.viewport.SetContent(builder.String())
	m.viewport.GotoBottom()
}

func (m *model) Init() tea.Cmd {
	return tea.Batch(
		m.checkConnection(),
		m.loadModels(),
	)
}

func (m *model) checkConnection() tea.Cmd {
	return func() tea.Msg {
		if err := m.agent.CheckConnection(); err != nil {
			return fmt.Errorf("Connection error: %v", err)
		}
		return nil
	}
}

func (m *model) loadModels() tea.Cmd {
	return func() tea.Msg {
		models, err := m.agent.ListModels()
		if err != nil {
			return nil
		}
		modelNames := make([]string, len(models))
		for i, mod := range models {
			modelNames[i] = mod.Name
		}
		return modelNames
	}
}

func (m *model) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		return m.handleKey(msg)
	case error:
		return m, tea.Printf("Error: %v", msg)
	default:
		return m, nil
	}
}

func (m *model) handleKey(msg tea.KeyMsg) (tea.Model, tea.Cmd) {
	switch msg.Type {
	case tea.KeyCtrlC:
		return m, tea.Quit
	case tea.KeyCtrlK:
		m.showingHelp = !m.showingHelp
		return m, nil
	case tea.KeyCtrlO:
		m.showingModelSelect = !m.showingModelSelect
		return m, nil
	case tea.KeyCtrlA:
		m.showingSessionSelect = !m.showingSessionSelect
		return m, nil
	case tea.KeyEnter:
		if m.input.Value() != "" {
			return m, m.sendMessage()
		}
	}
	
	if !m.input.Focused() {
		var cmd tea.Cmd
		m.input, cmd = m.input.Update(msg)
		return m, cmd
	}
	
	var cmd tea.Cmd
	m.input, cmd = m.input.Update(msg)
	return m, cmd
}

func (m *model) sendMessage() tea.Cmd {
	content := m.input.Value()
	m.input.Reset()
	
	m.messages = append(m.messages, MessageItem{
		ID:      newID(),
		Role:    "user",
		Content: content,
	})
	m.updateViewport()
	
	m.streaming = true
	m.currentResponse = ""
	
	return func() tea.Msg {
		ctx := context.Background()
		
		clientMsgs := make([]models.Message, len(m.messages))
		for i, msg := range m.messages {
			clientMsgs[i] = models.Message{
				Role:    msg.Role,
				Content: msg.Content,
			}
		}
		
		if err := m.agent.Run(ctx, clientMsgs); err != nil {
			return fmt.Errorf("Error: %v", err)
		}
		
		m.messages = append(m.messages, MessageItem{
			ID:      newID(),
			Role:    "assistant",
			Content: m.currentResponse,
		})
		
		m.streaming = false
		m.currentResponse = ""
		
		return nil
	}
}

func (m *model) View() string {
	var s strings.Builder
	
	s.WriteString(m.viewport.View())
	s.WriteString("\n")
	s.WriteString(theme.SurfaceStyle.Render(m.input.View()))
	s.WriteString("\n")
	
	if m.showingHelp {
		s.WriteString(renderHelp())
	}
	
	if m.showingModelSelect {
		s.WriteString(renderModelSelect(m.models, m.selectedModel))
	}
	
	s.WriteString(renderStatusBar(m.selectedModel, len(m.messages)))
	
	return s.String()
}

func newID() string {
	return fmt.Sprintf("%d", time.Now().UnixNano())
}

func renderMessage(msg MessageItem) string {
	roleColor := lipgloss.Color("#D4D4D4")
	if msg.Role == "user" {
		roleColor = lipgloss.Color("#4FC1FF")
	} else if msg.Role == "assistant" {
		roleColor = lipgloss.Color("#4EC9B0")
	}
	
	var b strings.Builder
	b.WriteString(lipgloss.NewStyle().Foreground(roleColor).Bold(true).Render(msg.Role + ":"))
	b.WriteString("\n")
	b.WriteString(msg.Content)
	return b.String()
}

func renderHelp() string {
	return lipgloss.NewStyle().
		Background(lipgloss.Color("#252526")).
		Foreground(lipgloss.Color("#D4D4D4")).
		Render(`Keyboard Shortcuts:
  Ctrl+C: Quit
  Ctrl+K: Help
  Ctrl+O: Select Model
  Ctrl+A: Sessions
  Enter: Send message
  ?: Toggle help`)
}

func renderModelSelect(models []string, current string) string {
	var b strings.Builder
	b.WriteString("\nModels:\n")
	for _, m := range models {
		sel := "  "
		if m == current {
			sel = ">"
		}
		b.WriteString(fmt.Sprintf("%s %s\n", sel, m))
	}
	return b.String()
}

func renderStatusBar(model string, msgCount int) string {
	return lipgloss.NewStyle().
		Background(lipgloss.Color("#252526")).
		Foreground(lipgloss.Color("#808080")).
		Render(fmt.Sprintf("  Model: %s | Messages: %d | Ctrl+K: Help", model, msgCount))
}