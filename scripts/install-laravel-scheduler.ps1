$ErrorActionPreference = 'Stop'

$backendPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpPath = 'C:\xampp\php\php.exe'
$taskName = 'PFDA Contract Monitoring Laravel Scheduler'

if (-not (Test-Path $phpPath)) {
    throw "PHP was not found at $phpPath."
}

$action = New-ScheduledTaskAction `
    -Execute $phpPath `
    -Argument 'artisan schedule:run' `
    -WorkingDirectory $backendPath
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal `
    -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) `
    -LogonType Interactive `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Runs the PFDA Laravel scheduler every minute while the configured user is logged in.' `
    -Force | Out-Null

Write-Output "Registered '$taskName' to run every minute as $([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)."
