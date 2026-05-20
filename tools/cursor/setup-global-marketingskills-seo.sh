#!/usr/bin/env bash
# Install marketingskills SEO subset + 請神：SEO神 bootstrap globally.
set -euo pipefail

CURSOR_HOME="${CURSOR_HOME:-$HOME/.cursor}"
REPO_URL="https://github.com/coreyhaines31/marketingskills.git"
CACHE_DIR="$CURSOR_HOME/plugins/local/marketingskills"
SKILLS=(
  product-marketing seo-audit ai-seo programmatic-seo
  schema site-architecture content-strategy competitors
)

mkdir -p "$CURSOR_HOME/skills" "$CURSOR_HOME/skills-cursor" "$CURSOR_HOME/plugins/local"

if [[ ! -d "$CACHE_DIR/.git" ]]; then
  git clone --depth 1 "$REPO_URL" "$CACHE_DIR"
else
  git -C "$CACHE_DIR" pull --ff-only || true
fi

for s in "${SKILLS[@]}"; do
  ln -sfn "$CACHE_DIR/skills/$s" "$CURSOR_HOME/skills/$s"
done

# 請神：SEO神 — same content as repo .cursor/skills/seo-shen
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
if [[ -f "$REPO_ROOT/.cursor/skills/seo-shen/SKILL.md" ]]; then
  mkdir -p "$CURSOR_HOME/skills/seo-shen" "$CURSOR_HOME/skills-cursor/seo-shen"
  cp "$REPO_ROOT/.cursor/skills/seo-shen/SKILL.md" "$CURSOR_HOME/skills/seo-shen/SKILL.md"
  cp "$REPO_ROOT/.cursor/skills/seo-shen/SKILL.md" "$CURSOR_HOME/skills-cursor/seo-shen/SKILL.md"
else
  curl -fsSL "https://raw.githubusercontent.com/forrestchang/andrej-karpathy-skills/main/skills/karpathy-guidelines/SKILL.md" >/dev/null 2>&1 || true
  echo "Warning: repo seo-shen SKILL.md not found; run from cloned museder-restoreone" >&2
fi

echo "Installed marketingskills SEO (${#SKILLS[@]} skills) + seo-shen:"
echo "  Cache: $CACHE_DIR"
echo "  Skills: $CURSOR_HOME/skills/"
echo "  請神 path: $CURSOR_HOME/skills-cursor/seo-shen/SKILL.md"
