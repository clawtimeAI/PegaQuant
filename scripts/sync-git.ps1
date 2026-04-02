# 在仓库根目录执行: .\scripts\sync-git.ps1 "提交说明"
# 未配置 origin 时会提示先 git remote add origin <url>

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$null = git remote get-url origin 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "尚未配置远程仓库。请先执行:" -ForegroundColor Yellow
    Write-Host '  git remote add origin https://github.com/clawtimeAI/PegaQuant.git' -ForegroundColor Cyan
    Write-Host "详见 docs/GIT_SETUP.md"
    exit 1
}

$msg = if ($args.Count -ge 1 -and $args[0]) { $args[0] } else { "sync: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" }

git add -A
$status = git status --porcelain
if (-not $status) {
    Write-Host "没有需要提交的变更。"
    exit 0
}

git commit -m $msg
if ($LASTEXITCODE -ne 0) {
    Write-Host "git commit 失败。"
    exit $LASTEXITCODE
}

git push
exit $LASTEXITCODE
