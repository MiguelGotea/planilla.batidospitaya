# ==============================================================================
# Script de Guardado - Planilla Batidos Pitaya
# ==============================================================================

# 1. Moverse automaticamente a la raiz del proyecto
$expectedPath = Split-Path $PSScriptRoot -Parent
Set-Location -Path $expectedPath

Write-Host '--- INICIANDO PROCESO DE GUARDADO (PLANILLA) ---' -ForegroundColor Gray

if (-not (Test-Path '.git')) {
    Write-Host 'ERROR: No se detecto una carpeta .git' -ForegroundColor Red
    exit
}

# 2. Seguridad: Verificar rama dev
$currentBranch = git branch --show-current
if ($currentBranch -ne 'dev') {
    Write-Host "ERROR: Estas en la rama '$currentBranch'. Cambia a dev primero." -ForegroundColor Red
    exit
}

# 3. Sincronizar desde origin/main
Write-Host ''
Write-Host '1. Trayendo cambios de la nube (fetch + rebase)...' -ForegroundColor Cyan
git fetch origin
git rebase origin/main --autostash

if ($LASTEXITCODE -ne 0) {
    Write-Host 'ERROR: Problema al sincronizar. Revisa conflictos.' -ForegroundColor Red
    exit
}

# 4. Verificar si hay cambios locales
$cambios = git status --porcelain
if (-not $cambios) {
    Write-Host ''
    Write-Host 'INFO: No hay cambios locales para guardar. Todo esta al dia.' -ForegroundColor Green
} else {
    Write-Host ''
    Write-Host '2. Preparando tus cambios locales...' -ForegroundColor Cyan

    # Verificacion de archivos vacios (0 bytes)
    $emptyFiles = @()
    foreach ($line in ($cambios -split "`r?`n")) {
        if ([string]::IsNullOrWhiteSpace($line)) { continue }
        $file = $line.Substring(3).Trim().Trim('"')
        if (Test-Path -LiteralPath $file) {
            $item = Get-Item $file
            if ($item.PSIsContainer -ne $true -and $item.Length -eq 0) {
                $emptyFiles += $file
            }
        }
    }

    if ($emptyFiles.Count -gt 0) {
        Write-Host ""
        Write-Host "ADVERTENCIA: Se detectaron archivos VACIOS (0 bytes):" -ForegroundColor Yellow
        foreach ($ef in $emptyFiles) {
            Write-Host "   -> $ef" -ForegroundColor Red
        }
        Write-Host ""
        $confirmacion = Read-Host "Deseas continuar de todas formas? (S/N)"
        if ($confirmacion -notmatch "^[Ss]$") {
            Write-Host "Operacion cancelada." -ForegroundColor Yellow
            Write-Host ""
            Write-Host "Presiona Enter para cerrar..."
            Read-Host | Out-Null
            exit
        }
    }

    # Mensaje de commit
    Write-Host 'Escribe que cambiaste (breve):' -ForegroundColor Yellow
    $mensaje = Read-Host '>'

    if ([string]::IsNullOrWhiteSpace($mensaje)) {
        $mensaje = "Actualizacion automatica: $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
    }

    # Agregar archivos (excluyendo .sql por seguridad)
    git add .
    git reset HEAD -- '*.sql' 2>$null

    Write-Host 'Guardando commit local...' -ForegroundColor Gray
    git commit -m "$mensaje"

    Write-Host 'Subiendo cambios a GitHub (dev)...' -ForegroundColor Gray
    git push origin dev --force-with-lease

    # 5. Pull Request via GitHub CLI
    Write-Host ''
    Write-Host '3. Verificando Pull Request en GitHub...' -ForegroundColor Cyan
    $ghPath = Get-Command gh -ErrorAction SilentlyContinue
    if ($ghPath) {
        $prExists = gh pr list --head dev --base main --json number --jq '.[0].number'
        if ($prExists) {
            Write-Host "Pull Request #$prExists actualizado." -ForegroundColor Green
        } else {
            gh pr create --base main --head dev --title "$mensaje" --body "Enviado automaticamente via script."
            Write-Host 'Pull Request creado con exito.' -ForegroundColor Green
        }
    } else {
        Write-Host 'Nota: Instala GitHub CLI (gh) para crear PRs automaticamente.' -ForegroundColor Gray
    }
}

Write-Host ''
Write-Host 'Presiona Enter para cerrar...'
Read-Host
