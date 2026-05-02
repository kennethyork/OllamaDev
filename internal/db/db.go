package db

import (
	"database/sql"
	"fmt"
	"os"
	"path/filepath"

	_ "github.com/ncruces/go-sqlite3"
)

type DB struct {
	*sql.DB
}

func New(dbPath string) (*DB, error) {
	dir := filepath.Dir(dbPath)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return nil, fmt.Errorf("creating data dir: %w", err)
	}

	db, err := sql.Open("sqlite3", dbPath+"?_journal=WAL&_busy_timeout=5000")
	if err != nil {
		return nil, fmt.Errorf("opening db: %w", err)
	}

	if err := db.Ping(); err != nil {
		return nil, fmt.Errorf("pinging db: %w", err)
	}

	if err := migrate(db); err != nil {
		return nil, fmt.Errorf("migrating db: %w", err)
	}

	return &DB{db}, nil
}

func migrate(db *sql.DB) error {
	schema := `
	CREATE TABLE IF NOT EXISTS sessions (
		id TEXT PRIMARY KEY,
		title TEXT NOT NULL,
		model TEXT NOT NULL,
		summary_id TEXT,
		summary_content TEXT,
		created_at INTEGER NOT NULL,
		updated_at INTEGER NOT NULL
	);

	CREATE TABLE IF NOT EXISTS messages (
		id TEXT PRIMARY KEY,
		session_id TEXT NOT NULL,
		role TEXT NOT NULL,
		content TEXT NOT NULL,
		tool_calls TEXT,
		created_at INTEGER NOT NULL,
		FOREIGN KEY (session_id) REFERENCES sessions(id)
	);

	CREATE TABLE IF NOT EXISTS file_changes (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		session_id TEXT NOT NULL,
		path TEXT NOT NULL,
		change TEXT NOT NULL,
		timestamp INTEGER NOT NULL,
		FOREIGN KEY (session_id) REFERENCES sessions(id)
	);

	CREATE TABLE IF NOT EXISTS permissions (
		id TEXT PRIMARY KEY,
		tool TEXT NOT NULL,
		command TEXT NOT NULL,
		session_id TEXT NOT NULL,
		status TEXT NOT NULL DEFAULT 'pending',
		created_at INTEGER NOT NULL,
		FOREIGN KEY (session_id) REFERENCES sessions(id)
	);

	CREATE INDEX IF NOT EXISTS idx_messages_session ON messages(session_id);
	CREATE INDEX IF NOT EXISTS idx_file_changes_session ON file_changes(session_id);
	CREATE INDEX IF NOT EXISTS idx_permissions_session ON permissions(session_id);
	`

	_, err := db.Exec(schema)
	return err
}

func (db *DB) CreateSession(id, title, model string) error {
	_, err := db.Exec(
		`INSERT INTO sessions (id, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?)`,
		id, title, model, now(), now(),
	)
	return err
}

func (db *DB) GetSession(id string) (*Session, error) {
	row := db.QueryRow(`SELECT id, title, model, summary_id, summary_content, created_at, updated_at FROM sessions WHERE id = ?`, id)
	var s Session
	err := row.Scan(&s.ID, &s.Title, &s.Model, &s.SummaryID, &s.SummaryContent, &s.CreatedAt, &s.UpdatedAt)
	if err != nil {
		return nil, err
	}
	return &s, nil
}

func (db *DB) ListSessions() ([]Session, error) {
	rows, err := db.Query(`SELECT id, title, model, summary_id, summary_content, created_at, updated_at FROM sessions ORDER BY updated_at DESC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var sessions []Session
	for rows.Next() {
		var s Session
		if err := rows.Scan(&s.ID, &s.Title, &s.Model, &s.SummaryID, &s.SummaryContent, &s.CreatedAt, &s.UpdatedAt); err != nil {
			return nil, err
		}
		sessions = append(sessions, s)
	}
	return sessions, nil
}

func (db *DB) UpdateSessionTitle(id, title string) error {
	_, err := db.Exec(`UPDATE sessions SET title = ?, updated_at = ? WHERE id = ?`, title, now(), id)
	return err
}

func (db *DB) UpdateSessionSummary(id, summaryID, summaryContent string) error {
	_, err := db.Exec(`UPDATE sessions SET summary_id = ?, summary_content = ?, updated_at = ? WHERE id = ?`, summaryID, summaryContent, now(), id)
	return err
}

func (db *DB) CreateMessage(id, sessionID, role, content string, toolCalls string) error {
	_, err := db.Exec(
		`INSERT INTO messages (id, session_id, role, content, tool_calls, created_at) VALUES (?, ?, ?, ?, ?, ?)`,
		id, sessionID, role, content, toolCalls, now(),
	)
	return err
}

func (db *DB) GetMessages(sessionID string) ([]Message, error) {
	rows, err := db.Query(`SELECT id, session_id, role, content, tool_calls, created_at FROM messages WHERE session_id = ? ORDER BY created_at`, sessionID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var msgs []Message
	for rows.Next() {
		var m Message
		var tc sql.NullString
		if err := rows.Scan(&m.ID, &m.SessionID, &m.Role, &m.Content, &tc, &m.CreatedAt); err != nil {
			return nil, err
		}
		if tc.Valid {
			m.ToolCallsRaw = tc.String
		}
		msgs = append(msgs, m)
	}
	return msgs, nil
}

func (db *DB) AddFileChange(sessionID, path, change string) error {
	_, err := db.Exec(`INSERT INTO file_changes (session_id, path, change, timestamp) VALUES (?, ?, ?, ?)`, sessionID, path, change, now())
	return err
}

func (db *DB) GetFileChanges(sessionID string) ([]FileChange, error) {
	rows, err := db.Query(`SELECT id, session_id, path, change, timestamp FROM file_changes WHERE session_id = ? ORDER BY timestamp`, sessionID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var changes []FileChange
	for rows.Next() {
		var c FileChange
		if err := rows.Scan(&c.ID, &c.SessionID, &c.Path, &c.Change, &c.Timestamp); err != nil {
			return nil, err
		}
		changes = append(changes, c)
	}
	return changes, nil
}

func (db *DB) CreatePermission(id, tool, command, sessionID string) error {
	_, err := db.Exec(`INSERT INTO permissions (id, tool, command, session_id, status, created_at) VALUES (?, ?, ?, ?, 'pending', ?)`, id, tool, command, sessionID, now())
	return err
}

func (db *DB) GetPermission(id string) (string, error) {
	var status string
	err := db.QueryRow(`SELECT status FROM permissions WHERE id = ?`, id).Scan(&status)
	return status, err
}

func (db *DB) UpdatePermissionStatus(id, status string) error {
	_, err := db.Exec(`UPDATE permissions SET status = ? WHERE id = ?`, status, id)
	return err
}

func now() int64 {
	return time.Now().UnixMilli()
}

type Session struct {
	ID              string
	Title           string
	Model           string
	SummaryID       sql.NullString
	SummaryContent  sql.NullString
	CreatedAt       int64
	UpdatedAt       int64
}

type Message struct {
	ID            string
	SessionID     string
	Role          string
	Content       string
	ToolCallsRaw  string
	CreatedAt     int64
}

type FileChange struct {
	ID        int64
	SessionID string
	Path      string
	Change    string
	Timestamp int64
}