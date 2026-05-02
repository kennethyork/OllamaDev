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

	"ollamadev/internal/llm/agent"
	"ollamadev/internal/config"
	"ollamadev/internal/db"
	"ollamadev/internal/models"
	"ollamadev/internal/permission"
)

type page int

const (
	pageChat page = iota
	pageLogs
)

var (
	primary   = lipgloss.Color("#007ACC")
	success   = lipgloss.Color("#28A745")
	warning   = lipgloss.Color("#FFC107")
	errorCol  = lipgloss.Color("#DC3545")
	bgDark    = lipgloss.Color("#1E1E1E")
	bgSurface = lipgloss.Color("#252526")
	bgSelect  = lipgloss.Color("#094771")
	textDim   = lipgloss.Color("#808080")
	textBright = lipgloss.Color("#FFFFFF")
	roleUser  = lipgloss.Color("#4FC1FF")
	roleAsst  = lipgloss.Color("#4EC9B0")
	roleTool  = lipgloss.Color("#DCDCAA")
)

type model struct {
	cfg        *config.Config
	db         *db.DB
	agent      *agent.Agent
	permServ   *permission.Service

	messages   []messageItem
	sessions   []sessionItem
	sessionIdx int

	input    textarea.Model
	viewport viewport.Model

	page    page
	width   int
	height  int

	showingHelp     bool
	showingModel    bool
	showingSession  bool
	showingCommand  bool
	showingPermission bool

	modelIdx   int
	models     []string

	sessionDialogIdx int

	permTool    string
	permCommand string

	logLines []string

	currentSessionID string

	streaming bool
	response  string

	pendingToolResult string
}

type messageItem struct {
	ID        string
	Role      string
	Content   string
	ToolName  string
	ToolResult string
	Timestamp int64
	Streaming bool
}

type sessionItem struct {
	ID    string
	Title string
	Model string
	Time  string
}

func New(cfg *config.Config) (*model, error) {
	database, err := db.New(cfg.DbPath())
	if err != nil {
		return nil, fmt.Errorf("opening db: %w", err)
	}

	permServ := permission.New(database)
	agt := agent.New(cfg, database, permServ)

	sessions, _ := database.ListSessions()
	sessionItems := make([]sessionItem, len(sessions))
	for i, s := range sessions {
		sessionItems[i] = sessionItem{
			ID:    s.ID,
			Title: s.Title,
			Model: s.Model,
			Time:  fmt.Sprintf("%d", s.UpdatedAt),
		}
	}

	input := textarea.New()
	input.Placeholder = "Type a message..."
	input.Focus()
	input.SetWidth(80)
	input.SetHeight(3)

	vp := viewport.New(80, 20)
	vp.SetContent("Welcome to OllamaDev! Press Ctrl+K for help.")

	m := &model{
		cfg:              cfg,
		db:               database,
		agent:            agt,
		permServ:         permServ,
		sessions:         sessionItems,
		currentSessionID: currentSession(),
		input:            input,
		viewport:         vp,
	}

	if len(sessionItems) > 0 {
		m.sessionIdx = 0
	}

	modellist, _ := agt.ListModels()
	m.models = make([]string, len(modellist)+1)
	m.models[0] = cfg.Ollama.DefaultModel
	for i, mod := range modellist {
		m.models[i+1] = mod.Name
	}

	go m.loadMessages()

	return m, nil
}

func currentSession() string {
	return fmt.Sprintf("session_%d", time.Now().UnixMilli())
}

func (m *model) loadMessages() {
	if m.sessionIdx >= len(m.sessions) {
		return
	}
	sessionID := m.sessions[m.sessionIdx].ID
	msgs, _ := m.db.GetMessages(sessionID)
	for _, msg := range msgs {
		m.messages = append(m.messages, messageItem{
			ID:      msg.ID,
			Role:    msg.Role,
			Content: msg.Content,
		})
	}
	m.updateViewport()
}

func (m *model) Init() tea.Cmd {
	return tea.Batch(
		func() tea.Msg {
			if err := m.agent.CheckConnection(); err != nil {
				return fmt.Errorf("Connection error: %v", err)
			}
			return nil
		},
		func() tea.Msg {
			models, err := m.agent.ListModels()
			if err != nil {
				return nil
			}
			modelNames := make([]string, len(models)+1)
			modelNames[0] = m.cfg.Ollama.DefaultModel
			for i, mod := range models {
				modelNames[i+1] = mod.Name
			}
			return modelNames
		},
	)
}

func (m *model) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case []string:
		m.models = msg
		return m, nil

	case error:
		m.logLines = append(m.logLines, fmt.Sprintf("[ERROR] %v", msg))
		return m, nil

	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height
		m.viewport.Width = msg.Width
		m.viewport.Height = msg.Height - 5
		return m, nil

	case tea.KeyMsg:
		return m.handleKey(msg)
	}

	var cmd tea.Cmd
	m.input, cmd = m.input.Update(msg)
	return m, cmd
}

func (m *model) handleKey(msg tea.KeyMsg) (tea.Model, tea.Cmd) {
	switch msg.Type {
	case tea.KeyCtrlC:
		return m, tea.Quit

	case tea.KeyCtrlK:
		m.showingHelp = !m.showingHelp
		m.showingModel = false
		m.showingSession = false
		m.showingCommand = false
		return m, nil

	case tea.KeyCtrlO:
		m.showingModel = !m.showingModel
		m.showingHelp = false
		m.showingSession = false
		m.showingCommand = false
		return m, nil

	case tea.KeyCtrlA:
		m.showingSession = !m.showingSession
		m.showingHelp = false
		m.showingModel = false
		m.showingCommand = false
		return m, nil

	case tea.KeyCtrlL:
		if m.page == pageChat {
			m.page = pageLogs
		} else {
			m.page = pageChat
		}
		return m, nil

	case tea.KeyCtrlN:
		m.createSession()
		return m, nil

	case tea.KeyCtrlX:
		m.streaming = false
		return m, nil

	case tea.KeyEscape:
		m.showingHelp = false
		m.showingModel = false
		m.showingSession = false
		m.showingCommand = false
		m.showingPermission = false
		return m, nil

	case tea.KeyEnter:
		if m.showingModel {
			return m, m.selectModel()
		}
		if m.showingSession {
			return m, m.selectSession()
		}
		if m.input.Value() != "" {
			return m, m.sendMessage()
		}
	}

	if m.showingModel || m.showingSession {
		switch msg.Type {
		case tea.KeyUp:
			if m.showingModel && m.modelIdx > 0 {
				m.modelIdx--
			}
			if m.showingSession && m.sessionDialogIdx > 0 {
				m.sessionDialogIdx--
			}
			return m, nil
		case tea.KeyDown:
			if m.showingModel && m.modelIdx < len(m.models)-1 {
				m.modelIdx++
			}
			if m.showingSession && m.sessionDialogIdx < len(m.sessions)-1 {
				m.sessionDialogIdx++
			}
			return m, nil
		}
	}

	var cmd tea.Cmd
	m.input, cmd = m.input.Update(msg)
	return m, cmd
}

func (m *model) selectModel() tea.Cmd {
	if m.modelIdx >= 0 && m.modelIdx < len(m.models) {
		m.agent.SetModel(m.models[m.modelIdx])
	}
	m.showingModel = false
	return nil
}

func (m *model) selectSession() tea.Cmd {
	if m.sessionDialogIdx >= 0 && m.sessionDialogIdx < len(m.sessions) {
		m.sessionIdx = m.sessionDialogIdx
		m.messages = nil
		m.loadMessages()
	}
	m.showingSession = false
	return nil
}

func (m *model) createSession() {
	id := currentSession()
	title := fmt.Sprintf("Session %d", len(m.sessions)+1)
	m.db.CreateSession(id, title, m.agent.GetModel())
	m.sessions = append(m.sessions, sessionItem{
		ID:    id,
		Title: title,
		Model: m.agent.GetModel(),
		Time:  "now",
	})
	m.sessionIdx = len(m.sessions) - 1
	m.messages = nil
	m.currentSessionID = id
}

func (m *model) sendMessage() tea.Cmd {
	content := m.input.Value()
	m.input.Reset()

	msg := messageItem{
		ID:        fmt.Sprintf("msg_%d", time.Now().UnixMilli()),
		Role:      "user",
		Content:   content,
		Timestamp: time.Now().UnixMilli(),
	}
	m.messages = append(m.messages, msg)
	m.updateViewport()

	m.db.CreateMessage(msg.ID, m.currentSessionID, msg.Role, msg.Content, "")

	m.streaming = true
	m.response = ""

	return func() tea.Msg {
		ctx := context.Background()

		var modelMsgs []models.Message
		for _, msg := range m.messages {
			modelMsgs = append(modelMsgs, models.Message{
				Role:    msg.Role,
				Content: msg.Content,
			})
		}

		err := m.agent.Run(ctx, m.currentSessionID, modelMsgs, func(resp models.Message) {
			m.response += resp.Content
			m.updateViewport()
		})

		m.streaming = false

		if err != nil {
			return fmt.Errorf("agent error: %v", err)
		}

		asstMsg := messageItem{
			ID:        fmt.Sprintf("msg_%d", time.Now().UnixMilli()),
			Role:      "assistant",
			Content:   m.response,
			Timestamp: time.Now().UnixMilli(),
		}
		m.messages = append(m.messages, asstMsg)
		m.db.CreateMessage(asstMsg.ID, m.currentSessionID, asstMsg.Role, asstMsg.Content, "")

		m.response = ""
		m.updateViewport()

		return nil
	}
}

func (m *model) updateViewport() {
	var b strings.Builder

	for _, msg := range m.messages {
		roleColor := roleAsst
		if msg.Role == "user" {
			roleColor = roleUser
		} else if msg.Role == "tool" {
			roleColor = roleTool
		}
		b.WriteString(lipgloss.NewStyle().Foreground(roleColor).Bold(true).Render(msg.Role) + "\n")
		b.WriteString(msg.Content)
		b.WriteString("\n\n")
	}

	if m.streaming && m.response != "" {
		b.WriteString(lipgloss.NewStyle().Foreground(roleAsst).Render("assistant") + "\n")
		b.WriteString(m.response + "▌")
	}

	m.viewport.SetContent(b.String())
	m.viewport.GotoBottom()
}

func (m *model) View() string {
	var s strings.Builder

	s.WriteString(m.viewport.View())
	s.WriteString("\n")
	s.WriteString(m.input.View())
	s.WriteString("\n")

	if m.showingHelp {
		s.WriteString("\n" + renderHelp())
	}

	if m.showingModel && len(m.models) > 0 {
		s.WriteString("\n" + m.renderModelSelect())
	}

	if m.showingSession && len(m.sessions) > 0 {
		s.WriteString("\n" + m.renderSessionList())
	}

	tokenCount := agent.CountTokens(toMsg(m.messages))
	s.WriteString(statusBar(m.agent.GetModel(), tokenCount, len(m.messages)))

	return s.String()
}

func toMsg(items []messageItem) []models.Message {
	msgs := make([]models.Message, len(items))
	for i, item := range items {
		msgs[i] = models.Message{Role: item.Role, Content: item.Content}
	}
	return msgs
}

func renderHelp() string {
	help := lipgloss.NewStyle().Background(bgSurface).Width(50)
	keys := []string{
		"Ctrl+C: Quit",
		"Ctrl+K: Help",
		"Ctrl+O: Select model",
		"Ctrl+A: Switch session",
		"Ctrl+L: Logs",
		"Ctrl+N: New session",
		"Ctrl+X: Cancel",
		"Esc: Close",
	}
	return help.Render(strings.Join(keys, "\n"))
}

func (m *model) renderModelSelect() string {
	var b strings.Builder
	b.WriteString("Models:\n")
	for i, mod := range m.models {
		prefix := "  "
		if i == m.modelIdx {
			prefix = "> "
		}
		b.WriteString(prefix + mod + "\n")
	}
	return lipgloss.NewStyle().Background(bgSurface).Render(b.String())
}

func (m *model) renderSessionList() string {
	var b strings.Builder
	b.WriteString("Sessions:\n")
	for i, s := range m.sessions {
		prefix := "  "
		if i == m.sessionDialogIdx {
			prefix = "> "
		}
		b.WriteString(prefix + s.Title + "\n")
	}
	return lipgloss.NewStyle().Background(bgSurface).Render(b.String())
}

func statusBar(model string, tokens, msgs int) string {
	return lipgloss.NewStyle().
		Background(bgSurface).
		Foreground(textDim).
		Width(80).
		Render(fmt.Sprintf("  Model: %s | Tokens: %d | Msgs: %d | Ctrl+K: Help", model, tokens, msgs))
}