<#
PowerShell script to update php.ini upload limits safely.
Usage examples:
  # Interactive: will try to auto-detect php.ini or ask for path
  .\scripts\update_php_ini.ps1

  # Specify path and values
  .\scripts\update_php_ini.ps1 -PhpIniPath 'C:\xampp\php\php.ini' -UploadMax '100M' -PostMax '110M'

Notes:
 - Run PowerShell as Administrator to ensure the script can write to php.ini.
 - The script makes a timestamped backup before modifying.
 - It updates or inserts these directives:
   file_uploads, upload_max_filesize, post_max_size, memory_limit, max_execution_time, max_input_time
#>

param(
    [string]$PhpIniPath = '',
    [string]$UploadMax = '100M',
    [string]$PostMax = '110M',
    [string]$MemoryLimit = '512M',
    [int]$MaxExecutionTime = 300,
    [int]$MaxInputTime = 300
)

function Test-Admin {
    $current = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($current)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Find-PhpIni {
    $candidates = @(
        'C:\\xampp\\php\\php.ini',
        'C:\\Program Files\\PHP\\php.ini',
        'C:\\php\\php.ini',
        'C:\\wamp64\\bin\\php\\php.ini',
        'C:\\wamp64\\bin\\apache\\apache2.4.41\\bin\\php.ini'
    )
    foreach ($p in $candidates) {
        if (Test-Path $p) { return $p }
    }
    # Try to fetch from localhost info.php if available
    try {
        $u = 'http://localhost:8000/info.php'
        $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 2 -ErrorAction SilentlyContinue
        if ($r -and $r.Content) {
            if ($r.Content -match 'Loaded Configuration File</td>\s*<td class="v">([^<]+)</') {
                return $matches[1]
            } elseif ($r.Content -match 'Loaded Configuration File</td>\s*<td>([^<]+)</'){
                return $matches[1]
            }
        }
    } catch { }
    return $null
}

if (-not (Test-Admin)) {
    Write-Warning 'It is recommended to run this script in an elevated PowerShell (Run as Administrator) to ensure ability to write php.ini.'
}

if (-not $PhpIniPath) {
    $detected = Find-PhpIni
    if ($detected) {
        Write-Host "Detected php.ini at: $detected" -ForegroundColor Green
        $use = Read-Host "Use this file? (Y/n)"
        if ($use -eq 'n' -or $use -eq 'N') {
            $PhpIniPath = Read-Host 'Enter full path to php.ini'
        } else {
            $PhpIniPath = $detected
        }
    } else {
        Write-Host "Could not auto-detect php.ini." -ForegroundColor Yellow
        $PhpIniPath = Read-Host 'Enter full path to php.ini (e.g. C:\\xampp\\php\\php.ini)'
    }
}

if (-not (Test-Path $PhpIniPath)) {
    Write-Error "php.ini not found at path: $PhpIniPath"
    exit 1
}

# Read and backup
$timestamp = (Get-Date).ToString('yyyyMMdd_HHmmss')
$backup = "$PhpIniPath.bak.$timestamp"
Copy-Item -Path $PhpIniPath -Destination $backup -Force
Write-Host "Backup created: $backup" -ForegroundColor Green

$content = Get-Content -Raw -LiteralPath $PhpIniPath -ErrorAction Stop

function Set-Or-InsertDirective([string]$content, [string]$directive, [string]$value) {
    $pattern = "^\s*#?\s*" + [regex]::Escape($directive) + "\s*=.*$"
    if ($content -match '(?im)' + $pattern) {
        $new = [regex]::Replace($content, '(?im)' + $pattern, "$directive = $value")
    } else {
        # Append at the end
        $new = $content.TrimEnd() + "`r`n$directive = $value`r`n"
    }
    return $new
}

$original = $content
$content = Set-Or-InsertDirective $content 'file_uploads' 'On'
$content = Set-Or-InsertDirective $content 'upload_max_filesize' $UploadMax
$content = Set-Or-InsertDirective $content 'post_max_size' $PostMax
$content = Set-Or-InsertDirective $content 'memory_limit' $MemoryLimit
$content = Set-Or-InsertDirective $content 'max_execution_time' $MaxExecutionTime
$content = Set-Or-InsertDirective $content 'max_input_time' $MaxInputTime

# Save the file
Set-Content -LiteralPath $PhpIniPath -Value $content -Force
Write-Host "php.ini updated with the requested values." -ForegroundColor Green

# Show a short diff (lines changed)
Write-Host "--- Changes (context) ---" -ForegroundColor Cyan
$origLines = $original -split "`r?`n"
$newLines = $content -split "`r?`n"
for ($i = 0; $i -lt $origLines.Length -and $i -lt $newLines.Length; $i++) {
    if ($origLines[$i] -ne $newLines[$i]) {
        Write-Host "- $($origLines[$i])" -ForegroundColor Red
        Write-Host "+ $($newLines[$i])" -ForegroundColor Green
    }
}

Write-Host "DONE. Please restart Apache / PHP service or the built-in server to apply changes." -ForegroundColor Yellow
Write-Host "If you need, open http://localhost:8000/info.php and verify values (Loaded Configuration File)." -ForegroundColor Yellow
