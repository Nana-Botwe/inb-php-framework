# INB PHP Framework — Windows / XAMPP: register the scheduler and a queue worker as scheduled tasks.
# Run in an elevated PowerShell:   powershell -ExecutionPolicy Bypass -File deploy\windows-tasks.ps1
# Adjust the paths below first.

$php     = 'H:\xampp\php\php.exe'
$project = 'H:\xampp\htdocs\MVC Models'

# 1. Scheduler: every minute.
$action  = New-ScheduledTaskAction -Execute $php -Argument 'console schedule:run' -WorkingDirectory $project
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'INB Scheduler' -Action $action -Trigger $trigger -Description 'php console schedule:run' -Force

# 2. Queue worker: start at boot, restart on failure.
$action   = New-ScheduledTaskAction -Execute $php -Argument 'console queue:work --sleep=3' -WorkingDirectory $project
$trigger  = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
Register-ScheduledTask -TaskName 'INB Queue Worker' -Action $action -Trigger $trigger -Settings $settings -Description 'php console queue:work' -Force

Write-Host 'Scheduled tasks registered. Check them in Task Scheduler.'
