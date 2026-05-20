# Install marketingskills SEO + 請神：SEO神 (Windows).
# Run: powershell -ExecutionPolicy Bypass -File tools\cursor\setup-global-marketingskills-seo.ps1

$ErrorActionPreference = "Stop"
$CursorHome = Join-Path $env:USERPROFILE ".cursor"
$CacheDir = Join-Path $CursorHome "plugins\local\marketingskills"
$RepoUrl = "https://github.com/coreyhaines31/marketingskills.git"
$Skills = @(
  "product-marketing", "seo-audit", "ai-seo", "programmatic-seo",
  "schema", "site-architecture", "content-strategy", "competitors"
)

New-Item -ItemType Directory -Force -Path (Join-Path $CursorHome "skills") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $CursorHome "skills-cursor\seo-shen") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $CursorHome "plugins\local") | Out-Null

if (-not (Test-Path (Join-Path $CacheDir ".git"))) {
  git clone --depth 1 $RepoUrl $CacheDir
} else {
  git -C $CacheDir pull --ff-only 2>$null
}

foreach ($s in $Skills) {
  $target = Join-Path $CursorHome "skills\$s"
  if (Test-Path $target) { Remove-Item -Force $target -ErrorAction SilentlyContinue }
  cmd /c mklink /J $target (Join-Path $CacheDir "skills\$s") 2>$null
  if (-not (Test-Path $target)) {
    Copy-Item -Recurse -Force (Join-Path $CacheDir "skills\$s") $target
  }
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir "..\..")
$SeoShenSrc = Join-Path $RepoRoot ".cursor\skills\seo-shen\SKILL.md"
if (Test-Path $SeoShenSrc) {
  $dest1 = Join-Path $CursorHome "skills\seo-shen\SKILL.md"
  $dest2 = Join-Path $CursorHome "skills-cursor\seo-shen\SKILL.md"
  New-Item -ItemType Directory -Force -Path (Split-Path $dest1) | Out-Null
  Copy-Item -Force $SeoShenSrc $dest1
  Copy-Item -Force $SeoShenSrc $dest2
} else {
  Write-Warning "Clone museder-restoreone first, or copy seo-shen SKILL.md manually."
}

Write-Host "OK: marketingskills SEO + seo-shen installed under $CursorHome"
Write-Host "請神路徑: $(Join-Path $CursorHome 'skills-cursor\seo-shen\SKILL.md')"
Write-Host "新開 Agent 對話後送: <請神：SEO神>"
