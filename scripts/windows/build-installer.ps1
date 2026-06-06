# Build the OllamaDev ADE Windows installer (OllamaDev-ADE-Setup.exe).
#
# Self-contained: bundles a PHP 8.4 runtime (ffi + curl) + the Boson Windows DLL
# + the app + the agent CLI, so it runs with no PHP install. Boson is a runtime,
# not a compiler, so a Windows .exe has to bundle PHP — this is the equivalent of
# the Linux AppImage. Run on a windows-latest runner with PHP 8.4 on PATH
# (extensions: ffi, curl, mbstring, openssl, fileinfo). vendor/ is committed, so
# no composer step is needed.
$ErrorActionPreference = "Stop"
$root  = (Resolve-Path "$PSScriptRoot\..\..").Path
$ade   = Join-Path $root "Desktop\ollamadev-ade"
$stage = Join-Path $root ".build\win-stage"
$dist  = Join-Path $root "dist\binaries"

$ver = (Select-String -Path (Join-Path $root "src\00-header.php") -Pattern "OLLAMADEV_VERSION', '([^']+)'").Matches[0].Groups[1].Value
Write-Host "==> Building OllamaDev ADE Windows installer v$ver"

# 1. Stage the app payload (vendor is committed; the CLI is the prebuilt root binary).
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force "$stage\bin", "$stage\php" | Out-Null
foreach ($f in "index.php", "boson.config.php", "composer.json", "composer.lock") { Copy-Item (Join-Path $ade $f) $stage }
foreach ($d in "src", "public", "web", "vendor") { Copy-Item (Join-Path $ade $d) $stage -Recurse }
Copy-Item (Join-Path $root "ollamadev") "$stage\bin\ollamadev"
# Keep only the Windows Boson lib (drop linux/mac to slim the installer).
Get-ChildItem "$stage\vendor\boson-php\saucer\bin" -File | Where-Object { $_.Name -notlike "*windows*" } | Remove-Item -Force

# 2. Bundle PHP (from the runner's install) + the VC++ runtime DLLs.
$phpDir = Split-Path (Get-Command php).Source
Copy-Item "$phpDir\*" "$stage\php" -Recurse -Force
foreach ($d in "vcruntime140.dll", "vcruntime140_1.dll", "msvcp140.dll") {
    $p = Join-Path $env:WINDIR "System32\$d"
    if (Test-Path $p) { Copy-Item $p "$stage\php\" -Force }
}
if (-not (Test-Path "$stage\php\php-win.exe")) { Write-Host "  (note: php-win.exe not found — GUI will fall back to php.exe)" }

# 2b. Bundle the Whisper STT engine + model so /voice works offline out of the box
#     (the "bake-in"). The engine .exe comes from the whisper-windows release job;
#     the model from Hugging Face. Skip with STT_BUNDLE=0; choose STT_MODEL.
if ($env:STT_BUNDLE -ne "0") {
    $sttModel = if ($env:STT_MODEL) { $env:STT_MODEL } else { "base" }
    $modelName = if ($sttModel -eq "turbo") { "ggml-large-v3-turbo.bin" } else { "ggml-$sttModel.bin" }
    New-Item -ItemType Directory -Force "$stage\stt" | Out-Null
    try {
        Write-Host "  bundling whisper-windows-x64.exe + $modelName"
        Invoke-WebRequest -UseBasicParsing -Uri "https://github.com/kennethyork/OllamaDev/releases/latest/download/whisper-windows-x64.exe" -OutFile "$stage\stt\whisper-windows-x64.exe"
        Invoke-WebRequest -UseBasicParsing -Uri "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/$modelName" -OutFile "$stage\stt\$modelName"
    } catch {
        Write-Host "  (stt bundle skipped: $_) — /voice will auto-fetch on first use"
        Remove-Item "$stage\stt" -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# 3. Launchers — windowless GUI (.vbs) + a terminal (.bat). Both put the bundled
#    php on PATH so the desktop's CLI shell-outs use it, and set OLLAMADEV_BINARY.
@'
Set sh = CreateObject("WScript.Shell")
here = Left(WScript.ScriptFullName, InStrRev(WScript.ScriptFullName, "\"))
sh.Environment("PROCESS")("PATH") = here & "php;" & sh.Environment("PROCESS")("PATH")
sh.Environment("PROCESS")("OLLAMADEV_BINARY") = here & "bin\ollamadev"
If CreateObject("Scripting.FileSystemObject").FolderExists(here & "stt") Then sh.Environment("PROCESS")("OLLAMADEV_STT_DIR") = here & "stt"
sh.CurrentDirectory = here
exe = here & "php\php-win.exe"
If Not CreateObject("Scripting.FileSystemObject").FileExists(exe) Then exe = here & "php\php.exe"
sh.Run """" & exe & """ """ & here & "index.php""", 0, False
'@ | Set-Content "$stage\OllamaDev-ADE.vbs" -Encoding ascii
@'
@echo off
set "HERE=%~dp0"
set "PATH=%HERE%php;%PATH%"
set "OLLAMADEV_BINARY=%HERE%bin\ollamadev"
if exist "%HERE%stt" set "OLLAMADEV_STT_DIR=%HERE%stt"
"%HERE%php\php.exe" "%HERE%index.php" %*
'@ | Set-Content "$stage\OllamaDev-ADE.bat" -Encoding ascii

# 4. Icon (.ico) — a simple ">_" mark, generated so no binary is committed.
Add-Type -AssemblyName System.Drawing
$bmp = New-Object System.Drawing.Bitmap 256, 256
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$g.Clear([System.Drawing.Color]::FromArgb(13, 17, 23))
$pen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(88, 166, 255), 18)
$g.DrawLines($pen, @((New-Object System.Drawing.Point 72, 80), (New-Object System.Drawing.Point 128, 128), (New-Object System.Drawing.Point 72, 176)))
$g.DrawLine($pen, 140, 176, 196, 176)
$g.Dispose()
$icon = [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
$fs = [System.IO.File]::Create("$stage\ollamadev-ade.ico"); $icon.Save($fs); $fs.Close(); $fs.Dispose()

# 5. Compile with Inno Setup (install it if the runner doesn't have it).
$iscc = Join-Path ${env:ProgramFiles(x86)} "Inno Setup 6\ISCC.exe"
if (-not (Test-Path $iscc)) { choco install innosetup -y --no-progress | Out-Null }
New-Item -ItemType Directory -Force $dist | Out-Null
& $iscc "/DAppVersion=$ver" "/DStageDir=$stage" "/O$dist" (Join-Path $root "scripts\windows\ollamadev-ade.iss")
if ($LASTEXITCODE -ne 0) { throw "ISCC failed ($LASTEXITCODE)" }
Write-Host "==> Built $dist\OllamaDev-ADE-Setup.exe"
