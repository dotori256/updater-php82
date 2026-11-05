program MWAgent;

uses
  Windows,
  Forms,
  TlHelp32,
  SysUtils,
  Controls,
  Dialogs,
  IniFiles,
  Log4D,
  ufrmMain in 'ufrmMain.pas' {frmMain},
  ufrmDM in 'ufrmDM.pas' {dmMain: TDataModule},
  CmnType in '..\lib\CmnType.pas',
  UniPayLib in '..\lib\UniPayLib.pas',
  UniStrUtils in '..\lib\UniStrUtils.pas',
  UAgentInfo in '..\lib\UAgentInfo.pas',
  uAPIServices in '..\lib\uAPIServices.pas',
  uCommDevice in '..\lib\uCommDevice.pas',
  uCSText in '..\lib\uCSText.pas',
  uFileLog in '..\lib\uFileLog.pas',
  uHttpCallback in '..\lib\uHttpCallback.pas',
  uMsgQueue in '..\lib\uMsgQueue.pas',
  uPOSDevice in '..\lib\uPOSDevice.pas',
  uCreditFlow in '..\lib\uCreditFlow.pas',
  uReceiptTextUtil in '..\lib\uReceiptTextUtil.pas',
  uReceiptBuilder in '..\lib\devices\uReceiptBuilder.pas',
  uPrinterModel in '..\lib\devices\uPrinterModel.pas',
  uPrinterHwasung in '..\lib\devices\uPrinterHwasung.pas',
  uPrinterHMK825 in '..\lib\devices\uPrinterHMK825.pas',
  uPrinterHMK060 in '..\lib\devices\uPrinterHMK060.pas',
  uPrinterFactory in '..\lib\devices\uPrinterFactory.pas',
  uPrintDevice in '..\lib\uPrintDevice.pas',
  uPrintDeviceCOM in '..\lib\uPrintDeviceCOM.pas',
  uPrintDeviceUSB in '..\lib\uPrintDeviceUSB.pas',
  uPrintInterface in '..\lib\uPrintInterface.pas',
  uPrintQueueThread in '..\lib\uPrintQueueThread.pas',
  uSoundDevice in '..\lib\uSoundDevice.pas',
  WindowsEx in '..\lib\WindowsEx.pas',
  uStatusCheck in '..\lib\uStatusCheck.pas',
  sip2 in '..\lib\sip2.pas',
  uDBService in '..\lib\uDBService.pas',
  uSerialDevice in '..\lib\serial\uSerialDevice.pas',
  uQRDevice in '..\lib\serial\uQRDevice.pas',
  uRFDevice in '..\lib\serial\uRFDevice.pas',
  uAuthIntf in '..\lib\auth\uAuthIntf.pas',
  uAuthBase in '..\lib\auth\uAuthBase.pas',
  uAuthSNU in '..\lib\auth\uAuthSNU.pas',
  uAuthYSU in '..\lib\auth\uAuthYSU.pas',
  uAuthDKU in '..\lib\auth\uAuthDKU.pas',
  uAuthKHU in '..\lib\auth\uAuthKHU.pas',
  uAuthFactory in '..\lib\auth\uAuthFactory.pas',
  AES128Crypto in '..\lib\auth\AES128Crypto.pas',
  AutoUpdateBoot in 'AutoUpdateBoot.pas';

{$R *.res}
{$R 'ExecutionLevelAsAdmin.res' 'ExecutionLevelAsAdmin.rc'}


var
  Process32 : TProcessEntry32;
  FoundHandle: THandle; // the handle of the Windows object
  Next : BOOL;
  ProcId : DWORD;

  ExeName: String;

  Path, FileName: String;
  Appender: TLogFileAppender;
  Logger: TLogLogger;
begin
  ExeName:= ExtractFileName(Application.ExeName);

  Process32.dwSize:= SizeOf(TProcessEntry32);
  FoundHandle:= CreateToolHelp32Snapshot(TH32CS_SNAPPROCESS, 0);

  if Process32First(FoundHandle, Process32) then begin

    // 실행화일명과 process object 검색 / 저장
    repeat
      Next:= Process32Next(FoundHandle, Process32);

      if Next = False then Continue;

      GetWindowThreadProcessID( FoundHandle, @ProcId );
      if UpperCase(Process32.szExeFile) <> UpperCase(ExeName) then Continue;
      if GetCurrentProcessId = Process32.th32ProcessID then Continue;

      if MessageDlg( Format('현재 %s가 실행중입니다.'+#13#10+'종료후 다시 실행하시겠습니까?', [ExeName]), mtConfirmation, mbOKCancel, 0) = mrOk then begin
        ProcId:= DWORD(Process32.th32ProcessID);
        FoundHandle:= OpenProcess(PROCESS_ALL_ACCESS, TRUE, ProcId);

        // 검색된 process를 강제 종료시킨다
        TerminateProcess(FoundHandle, 0);
      end
      else begin
        // 검색된 프로그램에 알림
        SetForegroundWindow( FoundHandle );
        FlashWindow( FoundHandle, True );

        Exit;
      end;
    until not Next;

    CloseHandle(FoundHandle); // closes an open object handle
  end;

  // 자동 업데이트: 직접 실행된 경우(윈도우 시작 등) Updater.exe 로 넘기고 종료.
  // Updater 가 업데이트를 마친 뒤 MWAgent.exe 를 다시 실행하면 그대로 진행된다.
  if RedirectToUpdater then Exit;

  Path:= ExtractFilePath(ParamStr(0))+ 'Log';
  FileName:= 'Agent.' + FormatDateTime('yyyymmdd', now) + '.txt';

  Appender:= TLogRollingFileAppender.Create('Agent', Path + '\' + FileName);
  //Appender.Layout:= TLogPatternLayout.Create('[%-5p] ');

  TLogBasicConfigurator.ResetConfiguration;
  TLogBasicConfigurator.Configure;

  TLogLogger.GetRootLogger.Level:= All;
  TLogLogger.GetRootLogger.AddAppender(Appender);

  Logger:= TLogLogger.GetLogger('ePaySystem');


  Application.Initialize;
  Application.Title := 'Mobileware Agent';
  Application.CreateForm(TdmMain, dmMain);
  Application.CreateForm(TfrmMain, frmMain);
  Application.Run;
end.
