# MWAgent.exe 수정 방법 (델파이)

## 1. 유닛 추가
`AutoUpdateBoot.pas` 를 MWAgent 프로젝트 폴더에 복사하고 프로젝트에 추가합니다.
(Project › Add to Project)

## 2. MWAgent.dpr 수정
`begin` 바로 다음 줄에 한 줄만 넣습니다. `Application.Initialize` 보다 **앞**이어야 합니다.

```pascal
program MWAgent;

uses
  AutoUpdateBoot,      // ← 추가 (uses 맨 앞 권장)
  Forms,
  MainForm in 'MainForm.pas' {frmMain};

{$R *.res}

begin
  if RedirectToUpdater then Exit;   // ← 추가

  Application.Initialize;
  Application.CreateForm(TfrmMain, frmMain);
  Application.Run;
end.
```

## 동작
- 윈도우 시작프로그램 등으로 MWAgent.exe 가 실행됨
  → 같은 폴더의 Updater.exe 를 실행하고 바로 종료
  → Updater 가 업데이트 후 MWAgent.exe 실행 → 이번에는 정상 실행
- Updater.exe 가 없거나, `MWAgent.exe /noupdate` 로 실행하면 업데이트 없이 바로 실행
- 시작 인자(예: `/min`)는 Updater 를 거쳐 그대로 전달

## 주의
- **Updater.exe 2.0.1 이상**과 같이 써야 합니다. (이전 Updater 는 "런처가 실행했다"는 표시를 남기지 않음. 그래도 30초 반복 방지 장치로 무한 반복은 되지 않습니다)
- 중복 실행 방지(Mutex) 코드가 있다면 `RedirectToUpdater` **다음**에 두세요. 첫 실행이 Mutex 를 잡은 채로 Updater 를 띄우면 두 번째 실행이 막힐 수 있습니다.
- 윈도우 시작 시 런처 창이 보이지 않게 하려면 updater.ini 에 `ShowWindow=0` 을 넣으세요.
