# Quick Push to upstream (https://github.com/dev-osos/almoustafa.git)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8
$env:GIT_MERGE_AUTOEDIT = "no"

$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $projectPath

Write-Host ""
Write-Host "===== Quick Push =====" -ForegroundColor Cyan

# 1. Fetch latest from upstream
Write-Host "[1/5] Fetching from upstream..." -ForegroundColor Yellow
git fetch upstream main --quiet
if ($LASTEXITCODE -ne 0) {
    Write-Host "  WARNING: Could not fetch. Continuing..." -ForegroundColor DarkYellow
}

# 2. Stage all changes
Write-Host "[2/5] Staging changes..." -ForegroundColor Yellow
git add -A

# 3. Check if there is anything to commit
$status = git status --porcelain
if ([string]::IsNullOrWhiteSpace($status)) {
    Write-Host "  No local changes to commit." -ForegroundColor Cyan

    # Still push if local is ahead of upstream
    $ahead = git rev-list upstream/main..HEAD --count 2>$null
    if ($ahead -gt 0) {
        Write-Host "  Local is $ahead commit(s) ahead. Pushing..." -ForegroundColor Yellow
    } else {
        Write-Host "  Already up to date with upstream." -ForegroundColor Green
        exit 0
    }
} else {
    # 4. Commit
    $msg = "Update - $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
    Write-Host "[3/5] Committing: $msg" -ForegroundColor Yellow
    git commit -m $msg
    if ($LASTEXITCODE -ne 0) {
        Write-Host "  ERROR: Commit failed." -ForegroundColor Red
        exit 1
    }
}

# 5. Rebase on upstream to avoid merge conflicts prompt
Write-Host "[4/5] Rebasing on upstream/main..." -ForegroundColor Yellow
git rebase upstream/main
if ($LASTEXITCODE -ne 0) {
    Write-Host "  Rebase conflict detected. Aborting rebase..." -ForegroundColor Red
    git rebase --abort
    Write-Host "  Trying merge instead..." -ForegroundColor Yellow
    git merge upstream/main --no-edit
    if ($LASTEXITCODE -ne 0) {
        Write-Host "  ERROR: Merge failed. Resolve conflicts manually then run again." -ForegroundColor Red
        exit 1
    }
}

# 6. Push
Write-Host "[5/5] Pushing to upstream (dev-osos/almoustafa)..." -ForegroundColor Yellow
git push upstream main
if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "  Done! Changes pushed successfully." -ForegroundColor Green
} else {
    Write-Host "  ERROR: Push failed." -ForegroundColor Red
    Write-Host "  Try: git push upstream main --force-with-lease" -ForegroundColor DarkYellow
    exit 1
}
