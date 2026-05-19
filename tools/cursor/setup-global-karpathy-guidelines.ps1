# Install karpathy-guidelines for Cursor 請神模式 (Windows).
# Double-click or run: powershell -ExecutionPolicy Bypass -File tools\cursor\setup-global-karpathy-guidelines.ps1

$ErrorActionPreference = "Stop"
$CursorHome = Join-Path $env:USERPROFILE ".cursor"
$SkillUrl = "https://raw.githubusercontent.com/forrestchang/andrej-karpathy-skills/main/skills/karpathy-guidelines/SKILL.md"
$SkillsDir = Join-Path $CursorHome "skills\karpathy-guidelines"
$SkillsCursorDir = Join-Path $CursorHome "skills-cursor\karpathy-guidelines"

New-Item -ItemType Directory -Force -Path $SkillsDir | Out-Null
New-Item -ItemType Directory -Force -Path $SkillsCursorDir | Out-Null

Invoke-WebRequest -Uri $SkillUrl -OutFile (Join-Path $SkillsDir "SKILL.md")
Copy-Item -Force (Join-Path $SkillsDir "SKILL.md") (Join-Path $SkillsCursorDir "SKILL.md")

Write-Host "OK: karpathy-guidelines installed"
Write-Host "  $(Join-Path $SkillsDir 'SKILL.md')"
Write-Host "  $(Join-Path $SkillsCursorDir 'SKILL.md')  <-- 請神模式讀這個"
Write-Host ""
Write-Host "請在 Cursor 新開一個 Agent 對話，再送: <請神：寫代碼>"
