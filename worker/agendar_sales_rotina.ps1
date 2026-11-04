# ============================================================================
# Agenda a Sales Rotina Contínua no Agendador de Tarefas do Windows.
#
# Executa o worker/sales_worker.php a cada 1 minuto, como SYSTEM, com
# privilégios altos. O worker só importa de fato quando o estado é RUNNING e
# a proxima_execucao já passou (frequência mínima configurável = 30 min).
#
# COMO USAR:
#   1. Rode no PowerShell COMO ADMINISTRADOR.
#   2. Ajuste as variáveis abaixo se os caminhos forem diferentes no seu servidor.
#   3. Execute:  powershell -ExecutionPolicy Bypass -File .\worker\agendar_sales_rotina.ps1
#
# ANTES DE AGENDAR: configure e clique em "Iniciar rotina" na tela
# Sales -> Rotina Contínua (senão o estado fica IDLE e o worker não importa).
# ============================================================================

$TaskName   = "FurkiCycle - Sales Rotina Continua"
$PhpPath    = "C:\xampp\php\php.exe"
$WorkerPath = "C:\xampp\htdocs\furk-cycle\worker\sales_worker.php"
$WorkDir    = "C:\xampp\htdocs\furk-cycle"
$Interval   = 1   # minutos

Write-Host "Criando tarefa: $TaskName" -ForegroundColor Cyan

# Remove a tarefa se já existir (evita duplicar)
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Write-Host "Tarefa já existe. Removendo para recriar..." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

# Ação: rodar o worker via PHP
$Action = New-ScheduledTaskAction -Execute $PhpPath -Argument "`"$WorkerPath`"" -WorkingDirectory $WorkDir

# Gatilho: a cada $Interval minutos, indefinidamente (duração longa válida)
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $Interval) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

# Configurações: iniciar se disponível, não parar na bateria, uma instância por vez
$Settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

# Registra a tarefa como SYSTEM, com privilégios altos
Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger `
    -Settings $Settings -User "SYSTEM" -RunLevel Highest -Force | Out-Null

Write-Host ""
Write-Host "Tarefa criada com sucesso!" -ForegroundColor Green
Write-Host "  Nome:       $TaskName"
Write-Host "  Programa:   $PhpPath"
Write-Host "  Argumento:  $WorkerPath"
Write-Host "  Frequência: a cada $Interval minuto(s)"
Write-Host ""
Write-Host "Para testar (1 ciclo): schtasks /Run /TN `"$TaskName`""
Write-Host "Para listar:           schtasks /Query /TN `"$TaskName`""
Write-Host "Para remover:          schtasks /Delete /TN `"$TaskName`" /F"

# ----------------------------------------------------------------------------
# ALTERNATIVA (prompt de comando) — equivalente a este script:
#
# schtasks /Create /TN "FurkiCycle - Sales Rotina Continua" /TR \
#   "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\furk-cycle\worker\sales_worker.php\"" \
#   /SC MINUTE /MO 1 /RU SYSTEM /RL HIGHEST /F
# ----------------------------------------------------------------------------
