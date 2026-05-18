#!/usr/bin/env bash
# Install obra/superpowers as global Cursor skills + session hook.
set -euo pipefail

CURSOR_HOME="${CURSOR_HOME:-$HOME/.cursor}"
PLUGIN_DIR="$CURSOR_HOME/plugins/local/superpowers"
SKILLS_DIR="$CURSOR_HOME/skills"
HOOKS_DIR="$CURSOR_HOME/hooks"

mkdir -p "$CURSOR_HOME/plugins/local" "$SKILLS_DIR" "$HOOKS_DIR"

if [[ ! -d "$PLUGIN_DIR/.git" ]]; then
  git clone --depth 1 https://github.com/obra/superpowers.git "$PLUGIN_DIR"
else
  git -C "$PLUGIN_DIR" pull --ff-only || true
fi

for skill_path in "$PLUGIN_DIR"/skills/*/; do
  name=$(basename "$skill_path")
  ln -sfn "$skill_path" "$SKILLS_DIR/$name"
done

cp "$PLUGIN_DIR/hooks/session-start" "$HOOKS_DIR/"
cp "$PLUGIN_DIR/hooks/run-hook.cmd" "$HOOKS_DIR/"
chmod +x "$HOOKS_DIR/session-start"

cat > "$CURSOR_HOME/hooks.json" << 'EOF'
{
  "version": 1,
  "hooks": {
    "sessionStart": [
      {
        "command": "./hooks/run-hook.cmd session-start"
      }
    ]
  }
}
EOF

echo "Installed superpowers globally:"
echo "  Plugin:  $PLUGIN_DIR"
echo "  Skills:  $SKILLS_DIR ($(ls "$SKILLS_DIR" | wc -l) skills)"
echo "  Hooks:   $CURSOR_HOME/hooks.json"
