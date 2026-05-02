package tui

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"time"

	"github.com/charmbracelet/bubbles/textarea"
	"github.com/charmbracelet/bubbles/viewport"
	"github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"

	"ollamadev/internal/agent"
	"ollamadev/internal/config"
	"ollamadev/internal/db"
	"ollamadev/internal/models"
	"ollamadev/internal/permission"
	"ollamadev/internal/tui/render"
)

type page int

const (
	pageChat page = iota
	pageLogs
)

type model struct {
	cfg        *config.Config
	db         *db.DB
	agent      *agent.Agent
	permServ   *permission.Service

	messages   []MessageItem
	sessions   []SessionItem
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

func New(cfg *config.Config) (*model, error) {
	database, err := db.New(cfg.DbPath())
	if err != nil {
		return nil, fmt.Errorf("opening db: %w", err)
	}

	permServ := permission.New(database)
	agt := agent.New(cfg, database, permServ)

	sessions, _ := database.ListSessions()
	sessionItems := make([]SessionItem, len(sessions))
	for i, s := range sessions {
		sessionItems[i] = SessionItem{
			ID:    s.ID,
			Title: s.Title,
			Model: s.Model,
			Time:  formatTime(s.UpdatedAt),
		}
	}

	input := textarea.New()
	input.Placeholder = "Type a message... (Ctrl+E to open editor)"
	input.Focus()
	input.SetWidth(80)
	input.SetHeight(3)

	vp := viewport.New(80, 20)
	vp.SetContent("Welcome to OllamaDev!\n\nPress Ctrl+K for help, Ctrl+O to select a model.\n\nStart typing to chat...")

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

	models, _ := agt.ListModels()
	m.models = make([]string, len(models)+1)
	m.models[0] = cfg.Ollama.DefaultModel
	for i, mod := range models {
		m.models[i+1] = mod.Name
	}

	go m.loadMessages()

	return m, nil
}

func currentSession() string {
	return fmt.Sprintf("session_%d", time.Now().UnixMilli())
}

func formatTime(t time.Time) string {
	return t.Format("Jan 2 15:04")
}

func (m *model) loadMessages() {
	if m.sessionIdx >= len(m.sessions) {
		return
	}
	sessionID := m.sessions[m.sessionIdx].ID
	msgs, _ := m.db.GetMessages(sessionID)
	for _, msg := range msgs {
		m.messages = append(m.messages, MessageItem{
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

	case tea.KeyCtrlE:
		return m, m.openExternalEditor()

	case tea.KeyEscape:
		m.showingHelp = false
		m.showingModel = false
		m.showingSession = false
		m.showingCommand = false
		m.showingPermission = false
		return m, nil

	case tea.KeyEnter:
		if m.showingPermission {
			return m, m.handlePermission("a")
		}
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

func (m *model) handlePermission(response string) tea.Cmd {
	switch response {
	case "a":
		m.permServ.Approve("current")
		m.showingPermission = false
	case "d":
		m.permServ.Deny("current")
		m.showingPermission = false
	case "A":
		m.permServ.AllowTool(m.permTool, m.permCommand)
		m.showingPermission = false
	}
	return nil
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
	m.sessions = append(m.sessions, SessionItem{
		ID:    id,
		Title: title,
		Model: m.agent.GetModel(),
		Time:  "just now",
	})
	m.sessionIdx = len(m.sessions) - 1
	m.messages = nil
	m.currentSessionID = id
}

func (m *model) sendMessage() tea.Cmd {
	content := m.input.Value()
	m.input.Reset()

	msg := MessageItem{
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

		asstMsg := MessageItem{
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

func (m *model) openExternalEditor() tea.Cmd {
	return func() tea.Msg {
		tmpfile, err := os.CreateTemp("", "ollamadev-*.txt")
		if err != nil {
			return err
		}
		tmpfile.WriteString(m.input.Value())
		tmpfile.Close()

		editor := os.Getenv("EDITOR")
		if editor == "" {
			editor = "vim"
		}

		cmd := exec.Command("bash", "-c", fmt.Sprintf("%s %s", editor, tmpfile.Name()))
		cmd.Run()

		data, _ := os.ReadFile(tmpfile.Name())
		content := string(data)

		os.Remove(tmpfile.Name())

		return content
	}
}

func (m *model) updateViewport() {
	var b strings.Builder

	for _, msg := range m.messages {
		b.WriteString(render.RenderMessage(msg))
		b.WriteString("\n\n")
	}

	if m.streaming && m.response != "" {
		b.WriteString(render.MessageItem{
			Role:    "assistant",
			Content: m.response + "▌",
		}.RenderMessage())
	}

	m.viewport.SetContent(b.String())
	m.viewport.GotoBottom()
}

func (m *model) View() string {
	var s strings.Builder

	switch m.page {
	case pageChat:
		s.WriteString(m.viewport.View())
		s.WriteString("\n")
		s.WriteString(m.input.View())
		s.WriteString("\n")

		if m.showingHelp {
			s.WriteString("\n")
			s.WriteString(render.RenderHelpDialog())
		}

		if m.showingModel {
			s.WriteString("\n")
			s.WriteString(render.RenderModelSelect(m.models, m.modelIdx))
		}

		if m.showingSession {
			s.WriteString("\n")
			s.WriteString(render.RenderSessionList(m.sessions, m.sessionDialogIdx))
		}

		if m.showingPermission {
			s.WriteString("\n")
			s.WriteString(render.RenderPermissionDialog(m.permTool, m.permCommand))
		}

		tokenCount := agent.CountTokens(toMsg(m.messages))
		s.WriteString(render.RenderStatusBar(m.agent.GetModel(), tokenCount, len(m.messages)))

	case pageLogs:
		s.WriteString(render.RenderLogs(strings.Join(m.logLines, "\n")))
		s.WriteString("\n\n")
		s.WriteString(render.RenderStatusBar(m.agent.GetModel(), 0, 0))
	}

	return s.String()
}

func toMsg(items []MessageItem) []models.Message {
	msgs := make([]models.Message, len(items))
	for i, item := range items {
		msgs[i] = models.Message{Role: item.Role, Content: item.Content}
	}
	return msgs
}