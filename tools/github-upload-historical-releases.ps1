# One-time helper: upload /dist/*.zip to GitHub Releases (requires gh CLI + git).
# Usage: .\tools\github-upload-historical-releases.ps1 -Repo "owner/museder-restoreone"

param(
    [Parameter(Mandatory = $true)]
    [string] $Repo
)

$ErrorActionPreference = "Stop"
$dist = Join-Path $PSScriptRoot "..\dist" | Resolve-Path

Get-ChildItem $dist -Filter "museder-restoreone-*.zip" | Sort-Object Name | ForEach-Object {
    if ($_.Name -notmatch '2\.7\.(\d+(?:-\w+)?)') { return }
    $ver = $Matches[0]
    $tag = "v$ver"
    Write-Host "Release $tag <- $($_.Name)"
    gh release view $tag --repo $Repo 2>$null
    if ($LASTEXITCODE -ne 0) {
        gh release create $tag $_.FullName --repo $Repo --title "Museder RestoreOne $ver" --notes "Historical build archived from dist/."
    } else {
        gh release upload $tag $_.FullName --repo $Repo --clobber
    }
}

Write-Host "Done."
