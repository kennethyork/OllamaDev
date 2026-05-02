package cmd

import (
	"fmt"
	"os"

	"github.com/charmbracelet/bubbletea"
	"github.com/spf13/cobra"

	"ollamadev/internal/config"
	"ollamadev/internal/tui"
)

var (
	debug     bool
	cwd       string
	prompt    string
	jsonOut   bool
	quiet     bool
)

var rootCmd = &cobra.Command{
	Use:   "ollamadev",
	Short: "Local AI coding agent using Ollama",
	Long:  "OllamaDev is a terminal-based AI coding agent that uses local Ollama models.",
	Run: func(cmd *cobra.Command, args []string) {
		cfg, err := config.Load()
		if err != nil {
			fmt.Fprintf(os.Stderr, "Error loading config: %v\n", err)
			os.Exit(1)
		}

		if cwd != "" {
			os.Chdir(cwd)
		}

		if prompt != "" {
			runPrompt(cfg, prompt)
			return
		}

		p := tea.NewProgram(tui.New(cfg))
		if err := p.Start(); err != nil {
			fmt.Fprintf(os.Stderr, "Error starting TUI: %v\n", err)
			os.Exit(1)
		}
	},
}

func runPrompt(cfg *config.Config, prompt string) {
	fmt.Println("Prompt mode not yet implemented. Use TUI mode without -p flag.")
}

func Execute() error {
	rootCmd.PersistentFlags().BoolVarP(&debug, "debug", "d", false, "Enable debug mode")
	rootCmd.PersistentFlags().StringVarP(&cwd, "cwd", "c", "", "Set working directory")
	rootCmd.PersistentFlags().StringVarP(&prompt, "prompt", "p", "", "Run a single prompt")
	rootCmd.Flags().BoolVarP(&jsonOut, "json", "f", false, "JSON output")
	rootCmd.Flags().BoolVarP(&quiet, "quiet", "q", false, "Hide spinner")

	return rootCmd.Execute()
}