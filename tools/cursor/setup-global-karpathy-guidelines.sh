#!/usr/bin/env bash
# Install karpathy-guidelines as a global Cursor skill (+ skills-cursor path for 請神模式).
set -euo pipefail

CURSOR_HOME="${CURSOR_HOME:-$HOME/.cursor}"
SKILL_URL="https://raw.githubusercontent.com/forrestchang/andrej-karpathy-skills/main/skills/karpathy-guidelines/SKILL.md"
SKILLS_DIR="$CURSOR_HOME/skills/karpathy-guidelines"
SKILLS_CURSOR_DIR="$CURSOR_HOME/skills-cursor/karpathy-guidelines"

mkdir -p "$SKILLS_DIR" "$SKILLS_CURSOR_DIR"
curl -fsSL "$SKILL_URL" -o "$SKILLS_DIR/SKILL.md"
cp "$SKILLS_DIR/SKILL.md" "$SKILLS_CURSOR_DIR/SKILL.md"

echo "Installed karpathy-guidelines globally:"
echo "  $SKILLS_DIR/SKILL.md"
echo "  $SKILLS_CURSOR_DIR/SKILL.md (請神模式 path)"
