<#
.SYNOPSIS
    Packages repository files for email submission by appending .txt
    to non-.txt text files (e.g., myscript.sh -> myscript.sh.txt).
#>
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$OutDir = Join-Path $RepoRoot "submission_txt"

if (Test-Path $OutDir) {
    Remove-Item -Recurse -Force $OutDir
}
New-Item -ItemType Directory -Path $OutDir | Out-Null

$ExcludedDirs = @(".git", "submission_txt", "dist", ".ansible")

Get-ChildItem -Path $RepoRoot -Recurse -File -Force | ForEach-Object {
    $RelPath = $_.FullName.Substring($RepoRoot.Length + 1)
    $Parts = $RelPath -split "[\\/]"
    foreach ($Ex in $ExcludedDirs) {
        if ($Parts -contains $Ex) { return }
    }
    if ($_.Name -eq ".env" -or $_.Extension -in @(".pem", ".key", ".crt")) {
        return
    }

    $DestRelPath = if ($_.Extension -eq ".txt") { $RelPath } else { "$RelPath.txt" }
    $DestFullPath = Join-Path $OutDir $DestRelPath
    $DestParent = Split-Path -Parent $DestFullPath

    if (-not (Test-Path $DestParent)) {
        New-Item -ItemType Directory -Path $DestParent -Force | Out-Null
    }
    Copy-Item -Path $_.FullName -Destination $DestFullPath -Force
}

Write-Host "Successfully packaged email submission files into: $OutDir" -ForegroundColor Green
