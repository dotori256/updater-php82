unit AutoUpdateBoot;

{ ---------------------------------------------------------------------------
  MWAgent.exe 가 직접 실행되면(윈도우 시작프로그램, 바로가기 등)
  같은 폴더의 Updater.exe 를 대신 실행하고 자신은 바로 종료합니다.
  Updater.exe 가 업데이트를 마친 뒤 MWAgent.exe 를 다시 실행하면 그때는 정상 실행됩니다.

  사용법: .dpr 의 begin 바로 다음 줄에
      if RedirectToUpdater then Exit;

  Updater 가 실행한 것인지 판단:
    1) 환경변수 AUTOUPDATER_LAUNCHED=1
    2) .update\launch.token 파일이 60초 이내에 만들어짐 (관리자 권한 실행 등 환경변수가 안 넘어오는 경우)
  안전장치:
    - Updater.exe 가 없으면 그냥 실행
    - /noupdate 인자로 실행하면 그냥 실행
    - 30초 안에 다시 넘기려 하면(구버전 Updater 등으로 반복될 때) 그냥 실행
  Delphi 7 ~ 12 공용
  --------------------------------------------------------------------------- }

interface

function RedirectToUpdater: Boolean;

implementation

uses
  Windows, SysUtils, ShellAPI;

const
  UPDATER_EXE = 'Updater.exe';
  WORK_DIR    = '.update\';
  TOKEN_FILE  = 'launch.token';
  STAMP_FILE  = 'redirect.stamp';
  TOKEN_SEC   = 60;   // Updater 가 실행한 것으로 인정하는 시간
  LOOP_SEC    = 30;   // 이 시간 안에 다시 넘기지 않음 (무한 반복 방지)

// 파일이 만들어진(수정된) 뒤 지난 초. 파일이 없으면 -1
function FileAgeSec(const FileName: string): Double;
var
  SR: TSearchRec;
begin
  Result := -1;
  if FindFirst(FileName, faAnyFile, SR) = 0 then
  begin
    Result := Abs(Now - FileDateToDateTime(SR.Time)) * 86400;
    FindClose(SR);
  end;
end;

// 받은 명령줄 인자를 그대로 Updater 에 전달 (Updater 가 다시 MWAgent 에 넘겨줌)
function PassArgs: string;
var
  I: Integer;
  A: string;
begin
  Result := '';
  for I := 1 to ParamCount do
  begin
    A := ParamStr(I);
    if (Pos(' ', A) > 0) or (A = '') then A := '"' + A + '"';
    if Result <> '' then Result := Result + ' ';
    Result := Result + A;
  end;
end;

function RedirectToUpdater: Boolean;
var
  Dir, Updater, Token, Stamp: string;
  Age: Double;
  F: TextFile;
begin
  Result := False;
  Dir := ExtractFilePath(ParamStr(0));
  Updater := Dir + UPDATER_EXE;
  Token := Dir + WORK_DIR + TOKEN_FILE;
  Stamp := Dir + WORK_DIR + STAMP_FILE;

  // 1) Updater 가 실행한 경우 → 정상 실행
  if GetEnvironmentVariable('AUTOUPDATER_LAUNCHED') = '1' then
  begin
    DeleteFile(Token);
    Exit;
  end;
  Age := FileAgeSec(Token);
  if Age >= 0 then
  begin
    DeleteFile(Token);
    if Age <= TOKEN_SEC then Exit;
  end;

  // 2) Updater 가 없거나 /noupdate 로 실행 → 정상 실행
  if not FileExists(Updater) then Exit;
  if FindCmdLineSwitch('noupdate', ['/', '-'], True) then Exit;

  // 3) 방금 넘겼는데 또 들어온 경우(반복) → 정상 실행
  Age := FileAgeSec(Stamp);
  if (Age >= 0) and (Age <= LOOP_SEC) then Exit;

  // 4) Updater 실행 후 종료
  try
    ForceDirectories(Dir + WORK_DIR);
    AssignFile(F, Stamp);
    Rewrite(F);
    Writeln(F, FormatDateTime('yyyy-mm-dd hh:nn:ss', Now));
    CloseFile(F);
  except
  end;
  if ShellExecute(0, 'open', PChar(Updater), PChar(PassArgs), PChar(Dir), SW_SHOWNORMAL) > 32 then
    Result := True;
end;

end.
