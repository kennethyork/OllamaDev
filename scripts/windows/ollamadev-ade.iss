; Inno Setup script for the OllamaDev ADE Windows installer.
; Produces OllamaDev-ADE-Setup.exe — a self-contained install that bundles a
; PHP 8.4 runtime (with ffi + curl), the Boson Windows DLL, the app, and the
; agent CLI, so it runs with no PHP install. Built by scripts/windows/build-installer.ps1
; (which passes /DAppVersion and /DStageDir). The staged payload is in StageDir.

#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif
#ifndef StageDir
  #define StageDir "..\..\.build\win-stage"
#endif

[Setup]
AppId={{B6F4B5C2-7A1E-4D8E-9E2A-0A1B2C3D4E5F}
AppName=OllamaDev ADE
AppVersion={#AppVersion}
AppPublisher=OllamaDev
AppPublisherURL=https://github.com/kennethyork/OllamaDev
DefaultDirName={autopf}\OllamaDev-ADE
DefaultGroupName=OllamaDev ADE
DisableProgramGroupPage=yes
UninstallDisplayName=OllamaDev ADE
UninstallDisplayIcon={app}\ollamadev-ade.ico
OutputBaseFilename=OllamaDev-ADE-Setup
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
SetupIconFile={#StageDir}\ollamadev-ade.ico
WizardStyle=modern

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Additional icons:"

[Files]
Source: "{#StageDir}\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion

[Icons]
Name: "{group}\OllamaDev ADE"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\OllamaDev-ADE.vbs"""; WorkingDir: "{app}"; IconFilename: "{app}\ollamadev-ade.ico"
Name: "{group}\OllamaDev ADE (terminal)"; Filename: "{app}\OllamaDev-ADE.bat"; WorkingDir: "{app}"; IconFilename: "{app}\ollamadev-ade.ico"
Name: "{autodesktop}\OllamaDev ADE"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\OllamaDev-ADE.vbs"""; WorkingDir: "{app}"; IconFilename: "{app}\ollamadev-ade.ico"; Tasks: desktopicon

[Run]
Filename: "{sys}\wscript.exe"; Parameters: """{app}\OllamaDev-ADE.vbs"""; Description: "Launch OllamaDev ADE"; Flags: nowait postinstall skipifsilent

[Code]
// Write the bundled PHP's php.ini with an ABSOLUTE extension_dir at install time
// (a relative path would resolve against the cwd and fail). php.exe auto-loads
// the ini that sits next to it, so both the GUI and the CLI shell-out get ffi+curl.
procedure CurStepChanged(CurStep: TSetupStep);
var ini: String;
begin
  if CurStep = ssPostInstall then begin
    ini :=
      'extension_dir=' + ExpandConstant('{app}\php\ext') + #13#10 +
      'extension=ffi'  + #13#10 +
      'extension=curl' + #13#10 +
      'extension=mbstring' + #13#10 +
      'extension=openssl'  + #13#10 +
      'extension=fileinfo' + #13#10 +
      'ffi.enable=true'    + #13#10;
    SaveStringToFile(ExpandConstant('{app}\php\php.ini'), ini, False);
  end;
end;
