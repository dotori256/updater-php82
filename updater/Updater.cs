// Updater.cs - 통합 런처 / 자동 업데이트 클라이언트
// .NET Framework 4.5+ (Windows 10/11 기본 내장), C# 5 문법만 사용 → Windows 내장 csc.exe 로 빌드 가능 (build.bat)
//
// 실행 흐름
//   1) 중복 실행 방지, 이전 업데이트가 중간에 끊겼으면 백업으로 복구
//   2) 서버에 업데이트 확인 (PC 자동 등록) → Manifest 수신
//   3) 로컬 파일과 비교 (경로/크기/SHA-256) → 바뀐 파일만 임시 폴더(.update\staging)에 다운로드 + 해시 검증
//   4) 기존 파일 백업(.update\backup) → 새 파일 적용 → 실패 시 자동 복구
//   5) 결과를 서버에 보고 (실패 시 다음 실행 때 재전송) → 프로그램 실행
//   서버 연결이 안 되면 솔루션 정책(오프라인 실행 허용)에 따라 기존 버전을 실행

using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;
using System.Windows.Forms;

namespace AutoUpdater
{
    // ======================================================================
    // 설정 / 상태
    // ======================================================================
    class Config
    {
        public string ServerUrl = "";
        public string App = "";
        public string ClientKey = "";
        public string SignKey = "";     // 배포 전자서명 공개 키 (있으면 서명된 배포만 설치)
        public string LaunchExe = "";
        public string LaunchArgs = "";
        public int TimeoutSec = 15;
        public bool ShowWindow = true;
        public bool AutoStart = true;
        public bool DeleteRemovedFiles = true;

        public static Config Load(string path)
        {
            Config c = new Config();
            foreach (KeyValuePair<string, string> kv in Ini.Read(path))
            {
                string k = kv.Key, v = kv.Value;
                if (k == "serverurl") c.ServerUrl = v;
                else if (k == "app") c.App = v;
                else if (k == "clientkey") c.ClientKey = v;
                else if (k == "signkey") c.SignKey = v;
                else if (k == "launchexe") c.LaunchExe = v;
                else if (k == "launchargs") c.LaunchArgs = v;
                else if (k == "timeoutsec") { int t; if (int.TryParse(v, out t) && t > 0) c.TimeoutSec = t; }
                else if (k == "showwindow") c.ShowWindow = Ini.IsTrue(v);
                else if (k == "autostart") c.AutoStart = Ini.IsTrue(v);
                else if (k == "deleteremovedfiles") c.DeleteRemovedFiles = Ini.IsTrue(v);
            }
            return c;
        }
    }

    static class Ini
    {
        public static Dictionary<string, string> Read(string path)
        {
            Dictionary<string, string> d = new Dictionary<string, string>();
            if (!File.Exists(path)) return d;
            foreach (string raw in File.ReadAllLines(path, Encoding.UTF8))
            {
                string line = raw.Trim().TrimStart('﻿');
                if (line.Length == 0 || line[0] == ';' || line[0] == '#' || line[0] == '[') continue;
                int eq = line.IndexOf('=');
                if (eq < 0) continue;
                d[line.Substring(0, eq).Trim().ToLowerInvariant()] = line.Substring(eq + 1).Trim();
            }
            return d;
        }

        public static void Write(string path, Dictionary<string, string> d)
        {
            StringBuilder sb = new StringBuilder();
            foreach (KeyValuePair<string, string> kv in d)
                sb.Append(kv.Key).Append('=').Append((kv.Value ?? "").Replace("\r", " ").Replace("\n", " ")).Append("\r\n");
            string tmp = path + ".tmp";
            File.WriteAllText(tmp, sb.ToString(), Encoding.UTF8);
            if (File.Exists(path)) File.Delete(path);
            File.Move(tmp, path);
        }

        public static bool IsTrue(string v)
        {
            v = (v ?? "").ToLowerInvariant();
            return v == "1" || v == "true" || v == "yes" || v == "y";
        }

        public static string Get(Dictionary<string, string> d, string k)
        {
            string v;
            return d.TryGetValue(k, out v) ? v : "";
        }
    }

    // 로컬 설치 상태 (.update\state.ini)
    class LocalState
    {
        public string Version = "";
        public int Build = 0;
        public string Name = "";
        public string Launch = "";
        public string LaunchArgs = "";
        public bool OfflineRun = true;
        public string Notice = "";
        public string ReleaseNote = "";

        public static LocalState Load(string path)
        {
            Dictionary<string, string> d = Ini.Read(path);
            LocalState s = new LocalState();
            s.Version = Ini.Get(d, "version");
            int.TryParse(Ini.Get(d, "build"), out s.Build);
            s.Name = Ini.Get(d, "name");
            s.Launch = Ini.Get(d, "launch");
            s.LaunchArgs = Ini.Get(d, "launchargs");
            s.OfflineRun = Ini.Get(d, "offlinerun") != "0";
            s.Notice = Ini.Get(d, "notice").Replace("\\n", "\n");
            s.ReleaseNote = Ini.Get(d, "releasenote").Replace("\\n", "\n");
            return s;
        }

        public void Save(string path)
        {
            Dictionary<string, string> d = new Dictionary<string, string>();
            d["version"] = Version; d["build"] = Build.ToString(); d["name"] = Name;
            d["launch"] = Launch; d["launchargs"] = LaunchArgs; d["offlinerun"] = OfflineRun ? "1" : "0";
            d["notice"] = (Notice ?? "").Replace("\r", "").Replace("\n", "\\n");
            d["releasenote"] = (ReleaseNote ?? "").Replace("\r", "").Replace("\n", "\\n");
            try { Ini.Write(path, d); } catch { }
        }
    }

    class ManifestFile
    {
        public string Path;
        public long Size;
        public string Sha256;
    }

    class Manifest
    {
        public string Signed = "";      // 서명된 원문 (JSON 문자열)
        public string Signature = "";   // base64 RSA-SHA256 서명
        public string Name = "";
        public string Version = "";
        public int Build;
        public string Launch = "";
        public string LaunchArgs = "";
        public string Notice = "";
        public string ReleaseNote = "";
        public bool Active = true;
        public bool OfflineRun = true;
        public List<ManifestFile> Files = new List<ManifestFile>();
        public List<string> Delete = new List<string>();
    }

    enum Outcome { Ready, Error }

    class RunResult
    {
        public Outcome Outcome = Outcome.Ready;
        public string Message = "";
        public bool CanRunExisting = true;
    }

    // ======================================================================
    // 런처 화면 (게임 클라이언트 스타일)
    // ======================================================================
    class LauncherForm : Form
    {
        static readonly Color Bg = Color.FromArgb(24, 28, 38);
        static readonly Color Panel2 = Color.FromArgb(34, 39, 52);
        static readonly Color Fg = Color.FromArgb(232, 236, 244);
        static readonly Color Muted = Color.FromArgb(140, 150, 170);
        static readonly Color Accent = Color.FromArgb(47, 111, 237);
        static readonly Color Danger = Color.FromArgb(230, 92, 92);

        public Label Title, Versions, Status, SizeText;
        public TextBox Notes;
        public Panel BarBack, BarFill;
        public Button BtnRun, BtnRetry, BtnClose;
        PictureBox Logo;

        public LauncherForm(string title, string logoPath)
        {
            Text = title;
            FormBorderStyle = FormBorderStyle.FixedSingle;
            MaximizeBox = false;
            StartPosition = FormStartPosition.CenterScreen;
            ClientSize = new Size(640, 400);
            BackColor = Bg; ForeColor = Fg;
            Font = new Font("Malgun Gothic", 9f);
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }

            Logo = new PictureBox();
            Logo.SetBounds(24, 22, 56, 56);
            Logo.SizeMode = PictureBoxSizeMode.Zoom;
            Logo.BackColor = Accent;
            if (logoPath != null && File.Exists(logoPath))
            {
                try { using (FileStream fs = new FileStream(logoPath, FileMode.Open, FileAccess.Read)) Logo.Image = Image.FromStream(new MemoryStream(ReadAll(fs))); Logo.BackColor = Bg; } catch { }
            }
            if (Logo.Image == null)
            {
                // exe 에 포함된 기본 로고 (bin 폴더에 logo.png 가 있으면 그걸 우선 사용)
                try
                {
                    Stream rs = typeof(LauncherForm).Assembly.GetManifestResourceStream("AutoUpdater.logo.png");
                    if (rs != null) using (rs) { Logo.Image = Image.FromStream(new MemoryStream(ReadAll(rs))); Logo.BackColor = Bg; }
                }
                catch { }
            }
            if (Logo.Image == null)
            {
                Bitmap bmp = new Bitmap(56, 56);
                using (Graphics g = Graphics.FromImage(bmp))
                using (Font f = new Font("Malgun Gothic", 22f, FontStyle.Bold))
                {
                    g.Clear(Accent);
                    g.TextRenderingHint = System.Drawing.Text.TextRenderingHint.AntiAliasGridFit;
                    string ch = title.Length > 0 ? title.Substring(0, 1).ToUpperInvariant() : "A";
                    SizeF sz = g.MeasureString(ch, f);
                    g.DrawString(ch, f, Brushes.White, (56 - sz.Width) / 2, (56 - sz.Height) / 2);
                }
                Logo.Image = bmp;
            }

            Title = MakeLabel(new Font("Malgun Gothic", 16f, FontStyle.Bold), Fg);
            Title.SetBounds(94, 22, 520, 32);
            Title.Text = title;
            Versions = MakeLabel(Font, Muted);
            Versions.SetBounds(96, 56, 520, 20);
            Versions.Text = "업데이트 확인 중...";

            Notes = new TextBox();
            Notes.Multiline = true; Notes.ReadOnly = true; Notes.ScrollBars = ScrollBars.Vertical;
            Notes.BorderStyle = BorderStyle.None;
            Notes.BackColor = Panel2; Notes.ForeColor = Fg;
            Notes.SetBounds(24, 96, 592, 176);
            Notes.TabStop = false;
            Panel notesBox = new Panel();
            notesBox.BackColor = Panel2;
            notesBox.SetBounds(16, 88, 608, 192);

            Status = MakeLabel(Font, Fg);
            Status.SetBounds(24, 292, 420, 20);
            SizeText = MakeLabel(Font, Muted);
            SizeText.SetBounds(444, 292, 172, 20);
            SizeText.TextAlign = ContentAlignment.MiddleRight;

            BarBack = new Panel(); BarBack.BackColor = Panel2; BarBack.SetBounds(24, 316, 592, 8);
            BarFill = new Panel(); BarFill.BackColor = Accent; BarFill.SetBounds(0, 0, 0, 8);
            BarBack.Controls.Add(BarFill);

            BtnRun = MakeButton("실행", Accent); BtnRun.SetBounds(476, 342, 140, 40);
            BtnRun.Font = new Font("Malgun Gothic", 11f, FontStyle.Bold);
            BtnRun.Enabled = false;
            BtnRetry = MakeButton("다시 시도", Panel2); BtnRetry.SetBounds(356, 342, 110, 40); BtnRetry.Visible = false;
            BtnClose = MakeButton("닫기", Panel2); BtnClose.SetBounds(24, 342, 90, 40);
            BtnClose.Click += delegate { Close(); };

            Controls.Add(Notes);
            Controls.Add(notesBox);
            Controls.AddRange(new Control[] { Logo, Title, Versions, Status, SizeText, BarBack, BtnRun, BtnRetry, BtnClose });
            Notes.BringToFront();
        }

        static byte[] ReadAll(Stream s) { MemoryStream ms = new MemoryStream(); s.CopyTo(ms); return ms.ToArray(); }

        Label MakeLabel(Font f, Color c)
        {
            Label l = new Label(); l.Font = f; l.ForeColor = c; l.BackColor = Color.Transparent; l.AutoEllipsis = true;
            return l;
        }

        Button MakeButton(string text, Color bg)
        {
            Button b = new Button();
            b.Text = text; b.FlatStyle = FlatStyle.Flat; b.BackColor = bg; b.ForeColor = Color.White;
            b.FlatAppearance.BorderSize = 0; b.Cursor = Cursors.Hand;
            return b;
        }

        void Ui(Action a)
        {
            if (IsDisposed) return;
            if (InvokeRequired) { try { BeginInvoke(a); } catch { } }
            else a();
        }

        public void SetStatus(string text, bool error)
        {
            Ui(delegate { Status.Text = text; Status.ForeColor = error ? Danger : Fg; });
        }

        public void SetProgress(int permille, string sizeText)
        {
            Ui(delegate
            {
                BarFill.Width = (int)(BarBack.Width * Math.Max(0, Math.Min(1000, permille)) / 1000.0);
                if (sizeText != null) SizeText.Text = sizeText;
            });
        }

        public void SetInfo(string title, string versions, string notes)
        {
            Ui(delegate
            {
                if (title != null) { Title.Text = title; Text = title; }
                if (versions != null) Versions.Text = versions;
                if (notes != null) Notes.Text = notes.Replace("\r\n", "\n").Replace("\n", "\r\n");
            });
        }
    }

    // ======================================================================
    // 런처 본체
    // ======================================================================
    static class Program
    {
        public const string LauncherVersion = "2.1.0";
        static readonly char Sep = Path.DirectorySeparatorChar;

        static string BaseDir, SelfPath, WorkDir, StagingDir, BackupDir, JournalPath, StatePath, LogPath, ReportsPath;
        static Config Cfg;
        static LocalState State;
        static LauncherForm Form;
        static string PcId = "";
        static string ExtraArgs = "";
        static bool Repair;

        [STAThread]
        static int Main(string[] args)
        {
            SelfPath = System.Reflection.Assembly.GetEntryAssembly().Location;
            BaseDir = Path.GetDirectoryName(SelfPath);
            WorkDir = Path.Combine(BaseDir, ".update");
            StagingDir = Path.Combine(WorkDir, "staging");
            BackupDir = Path.Combine(WorkDir, "backup");
            JournalPath = Path.Combine(WorkDir, "journal.txt");
            StatePath = Path.Combine(WorkDir, "state.ini");
            LogPath = Path.Combine(WorkDir, "updater.log");
            ReportsPath = Path.Combine(WorkDir, "reports.txt");
            try { Directory.CreateDirectory(WorkDir); File.SetAttributes(WorkDir, File.GetAttributes(WorkDir) | FileAttributes.Hidden); } catch { }
            TrimLog();

            // --repair : 해시 캐시를 무시하고 모든 파일을 다시 검사 (전체 동기화)
            List<string> pass = new List<string>();
            foreach (string a in args) { if (a.Equals("--repair", StringComparison.OrdinalIgnoreCase)) Repair = true; else pass.Add(a); }
            ExtraArgs = JoinArgs(pass.ToArray());

            try { ServicePointManager.SecurityProtocol |= (SecurityProtocolType)3072; } catch { }   // TLS 1.2
            Cfg = Config.Load(Path.Combine(BaseDir, "updater.ini"));
            State = LocalState.Load(StatePath);
            MigrateLegacy();

            // 같은 bin 폴더에서 런처 중복 실행 방지
            bool created;
            Mutex mutex = new Mutex(true, "Local\\AutoUpdater_" + Sha256Text(BaseDir.ToLowerInvariant()).Substring(0, 16), out created);
            if (!created)
            {
                if (Cfg.ShowWindow) MessageBox.Show("업데이트가 이미 진행 중입니다. 잠시 후 다시 실행하세요.", "런처", MessageBoxButtons.OK, MessageBoxIcon.Information);
                return 4;
            }
            try { return Run(); }
            finally { try { mutex.ReleaseMutex(); } catch { } }
        }

        static int Run()
        {
            string title = State.Name.Length > 0 ? State.Name : (Cfg.App.Length > 0 ? Cfg.App : "프로그램");
            if (!Cfg.ShowWindow)
            {
                RunResult r = UpdateSafe();
                if (r.Outcome == Outcome.Error && !r.CanRunExisting) return 5;
                return Launch(false) ? 0 : 2;
            }

            Application.EnableVisualStyles();
            Form = new LauncherForm(title, FindLogo());
            Form.SetInfo(null, "설치 버전 " + (State.Version.Length > 0 ? "v" + State.Version : "없음"), NotesText(State.Notice, State.Version, State.ReleaseNote));
            int exitCode = 0;
            bool launched = false;

            Action start = null;
            start = delegate
            {
                Form.BtnRun.Enabled = false; Form.BtnRetry.Visible = false;
                Thread t = new Thread(delegate ()
                {
                    RunResult r = UpdateSafe();
                    try
                    {
                        Form.BeginInvoke(new Action(delegate
                        {
                            if (r.Outcome == Outcome.Ready)
                            {
                                Form.BtnRun.Enabled = true;
                                if (Cfg.AutoStart) Form.BtnRun.PerformClick();
                            }
                            else
                            {
                                Form.SetStatus(r.Message, true);
                                Form.BtnRetry.Visible = true;
                                Form.BtnRun.Enabled = r.CanRunExisting && LaunchTarget() != null;
                                Form.BtnRun.Text = Form.BtnRun.Enabled ? "기존 버전 실행" : "실행";
                            }
                        }));
                    }
                    catch { }
                });
                t.IsBackground = true;
                t.Start();
            };
            Form.Shown += delegate { start(); };
            Form.BtnRetry.Click += delegate { Form.SetProgress(0, ""); start(); };
            Form.BtnRun.Click += delegate
            {
                Form.SetStatus("프로그램 실행 중...", false);
                launched = Launch(true);
                if (launched) Form.Close(); else exitCode = 2;
            };
            Application.Run(Form);
            return launched ? 0 : (exitCode != 0 ? exitCode : 1);
        }

        static string FindLogo()
        {
            foreach (string n in new string[] { "logo.png", "logo.jpg", "logo.bmp" })
            {
                string p = Path.Combine(BaseDir, n);
                if (File.Exists(p)) return p;
            }
            return null;
        }

        static string NotesText(string notice, string version, string releaseNote)
        {
            StringBuilder sb = new StringBuilder();
            if (!string.IsNullOrEmpty(notice)) sb.Append("[공지]\n").Append(notice.Trim()).Append("\n\n");
            if (!string.IsNullOrEmpty(releaseNote)) sb.Append("[업데이트 내용").Append(string.IsNullOrEmpty(version) ? "" : " v" + version).Append("]\n").Append(releaseNote.Trim());
            return sb.ToString();
        }

        static void Status(string text) { Log(text); if (Form != null) Form.SetStatus(text, false); }
        static void Progress(int permille, string size) { if (Form != null) Form.SetProgress(permille, size); }

        // ------------------------------------------------------------------
        // 업데이트
        // ------------------------------------------------------------------
        static RunResult UpdateSafe()
        {
            try { return Update(); }
            catch (Exception ex)
            {
                Log("오류: " + ex);
                RunResult r = new RunResult();
                r.Outcome = Outcome.Error;
                r.Message = "업데이트 오류: " + ex.Message;
                Report("fail", State.Version, State.Version, State.Build, 0, 0, 0, ex.Message);
                return r;
            }
        }

        static RunResult Update()
        {
            RunResult result = new RunResult();
            Stopwatch sw = Stopwatch.StartNew();

            // 1) 지난번 업데이트가 적용 도중 끊겼다면 백업으로 복구
            if (File.Exists(JournalPath))
            {
                Status("이전 업데이트 복구 중...");
                string err = RestoreFromJournal();
                Report("recovered", State.Version, State.Version, State.Build, 0, 0, 0, "이전 업데이트가 중간에 중단되어 기존 버전으로 복구" + (err.Length > 0 ? " (일부 실패: " + err + ")" : ""));
            }
            CleanupWorkDirs();
            CleanupOldFiles();
            PcId = GetPcId();
            FlushReports();

            if (Cfg.ServerUrl.Length == 0) { Log("updater.ini 에 ServerUrl 이 없어 업데이트를 건너뜁니다."); Status("서버 설정이 없어 업데이트를 건너뜁니다."); return result; }
            string baseUrl = Cfg.ServerUrl.TrimEnd('/') + "/";

            // 2) 업데이트 확인
            Status("업데이트 확인 중...");
            Dictionary<string, object> check = null;
            string manifestUrl, filesUrl = baseUrl + "files/";
            bool legacy = false;
            try
            {
                if (Cfg.App.Length == 0) throw new LegacyException();
                Dictionary<string, object> req = BaseRequest();
                req["currentVersion"] = State.Version;
                req["currentBuild"] = State.Build;
                check = PostJson(baseUrl + "api/v1/launcher/check.php", req, Cfg.TimeoutSec);
                manifestUrl = Str(check, "manifestUrl");
                if (Str(check, "filesUrl").Length > 0) filesUrl = Str(check, "filesUrl");
            }
            catch (LegacyException) { legacy = true; manifestUrl = baseUrl + (Cfg.App.Length > 0 ? "manifests/" + Uri.EscapeDataString(Cfg.App) + ".json" : "manifest.json"); }
            catch (WebException ex)
            {
                HttpWebResponse hr = ex.Response as HttpWebResponse;
                string em = ReadError(ex);   // 응답 본문은 한 번만 읽을 수 있음
                if (hr != null && (int)hr.StatusCode == 404 && em.Length == 0)
                {
                    legacy = true;   // 런처 API 가 없는 서버 → Manifest 만 사용
                    manifestUrl = baseUrl + "manifests/" + Uri.EscapeDataString(Cfg.App) + ".json";
                }
                else if (hr != null) { throw new Exception("서버 응답 오류: " + (em.Length > 0 ? em : ((int)hr.StatusCode).ToString())); }
                else return Offline(result, ex.Message);
            }

            if (check != null)
            {
                bool blocked = Bool(check, "blocked", false), active = Bool(check, "active", true);
                State.OfflineRun = Bool(check, "offlineRun", true);
                State.Notice = Str(check, "notice");
                if (Str(check, "name").Length > 0) State.Name = Str(check, "name");
                if (blocked || !active)
                {
                    string why = blocked ? "이 PC는 관리자가 업데이트를 중지했습니다." : "현재 이 솔루션의 업데이트가 중지되어 있습니다.";
                    Status(why + " 현재 버전으로 실행합니다.");
                    Form_Info(State.Version, Str(check, "latestVersion"), State.Notice, State.Version, State.ReleaseNote);
                    State.Save(StatePath);
                    Progress(1000, "");
                    return result;
                }
            }

            // 3) Manifest 수신
            Manifest m;
            try { m = ParseManifest(GetString(manifestUrl + (manifestUrl.Contains("?") ? "&" : "?") + "t=" + DateTime.UtcNow.Ticks, Cfg.TimeoutSec)); }
            catch (WebException ex)
            {
                if (legacy && ex.Response == null) return Offline(result, ex.Message);
                throw new Exception("파일 목록을 받지 못했습니다: " + ex.Message);
            }
            if (Cfg.SignKey.Length > 0) VerifyManifest(m);   // 서명이 없거나 틀리면 여기서 중단 (설치 폴더는 그대로)
            if (legacy && !m.Active) { Status("현재 이 솔루션의 업데이트가 중지되어 있습니다."); return result; }
            State.OfflineRun = m.OfflineRun;
            if (m.Name.Length > 0) State.Name = m.Name;
            if (m.Notice.Length > 0 || check != null) State.Notice = check != null ? Str(check, "notice") : m.Notice;
            Form_Info(State.Version, m.Version, State.Notice, m.Version, m.ReleaseNote);

            // 4) 파일 비교
            Status("설치 파일 확인 중...");
            HashCache cache = new HashCache(Path.Combine(WorkDir, "hash.cache"), Repair);
            List<ManifestFile> todo = new List<ManifestFile>();
            long totalBytes = 0;
            for (int i = 0; i < m.Files.Count; i++)
            {
                ManifestFile f = m.Files[i];
                Progress(i * 100 / Math.Max(1, m.Files.Count), null);
                string local = SafeLocalPath(f.Path);
                if (local == null) { Log("잘못된 경로 무시: " + f.Path); continue; }
                bool need;
                if (!File.Exists(local)) need = true;
                else
                {
                    FileInfo fi = new FileInfo(local);
                    need = fi.Length != f.Size || !string.Equals(cache.GetHash(local, fi), f.Sha256, StringComparison.OrdinalIgnoreCase);
                }
                if (need) { todo.Add(f); totalBytes += f.Size; }
            }
            List<string> deletes = new List<string>();
            if (Cfg.DeleteRemovedFiles)
                foreach (string d in m.Delete)
                {
                    string local = SafeLocalPath(d);
                    if (local != null && File.Exists(local) && !SamePath(local, SelfPath)) deletes.Add(d);
                }
            cache.Save();

            if (todo.Count == 0 && deletes.Count == 0)
            {
                string prev = State.Version;
                bool changed = State.Build != m.Build;
                ApplyStateFromManifest(m);
                Progress(1000, "");
                Status("최신 버전입니다.");
                Report("uptodate", prev, m.Version, m.Build, 0, 0, sw.ElapsedMilliseconds, changed ? "파일이 이미 최신 상태" : "");
                return result;
            }
            Log("업데이트 대상: 다운로드 " + todo.Count + "개 (" + FmtSize(totalBytes) + "), 삭제 " + deletes.Count + "개");

            // 5) 임시 폴더로 다운로드 + 검증 (이 단계에서 실패하면 설치 폴더는 전혀 바뀌지 않음)
            Directory.CreateDirectory(StagingDir);
            long done = 0;
            for (int i = 0; i < todo.Count; i++)
            {
                ManifestFile f = todo[i];
                Status("업데이트 파일 다운로드 중 (" + (i + 1) + "/" + todo.Count + ") " + f.Path);
                string staged = Path.Combine(StagingDir, f.Sha256.ToLowerInvariant());
                if (File.Exists(staged) && new FileInfo(staged).Length == f.Size && FileSha256(staged) == f.Sha256.ToLowerInvariant()) { done += f.Size; continue; }
                Exception last = null;
                for (int attempt = 1; attempt <= 3; attempt++)
                {
                    try
                    {
                        long before = done;
                        Download(filesUrl + f.Sha256.ToLowerInvariant(), staged, f, delegate (long got)
                        {
                            Progress(100 + (int)((before + got) * 800 / Math.Max(1, totalBytes)), FmtSize(before + got) + " / " + FmtSize(totalBytes));
                        });
                        last = null;
                        break;
                    }
                    catch (Exception ex) { last = ex; Log("다운로드 실패 (" + attempt + "/3) " + f.Path + ": " + ex.Message); Thread.Sleep(1000 * attempt); }
                }
                if (last != null)
                {
                    string msg = "다운로드 실패: " + f.Path + " (" + last.Message + ")";
                    Report("fail", State.Version, m.Version, m.Build, todo.Count, done, sw.ElapsedMilliseconds, msg + " → 기존 버전 유지");
                    result.Outcome = Outcome.Error;
                    result.Message = msg + "\n기존 버전은 그대로 유지됩니다.";
                    result.CanRunExisting = true;
                    return result;
                }
                done += f.Size;
            }

            // 6) 실행 중인 프로그램이 있으면 교체하지 않음
            // 프로그램이 런처를 띄우고 막 종료하는 중일 수 있으므로 최대 10초 기다림
            string running = FindRunningProgram();
            for (int w = 0; running != null && w < 20; w++)
            {
                if (w == 0) Status("프로그램이 종료되기를 기다리는 중... (" + running + ")");
                Thread.Sleep(500);
                running = FindRunningProgram();
            }
            if (running != null)
            {
                result.Outcome = Outcome.Error;
                result.Message = "실행 중인 프로그램(" + running + ")을 종료한 뒤 [다시 시도]를 눌러주세요.";
                result.CanRunExisting = false;
                Log(result.Message);
                return result;
            }

            // 7) 백업 → 적용 (실패 시 복구)
            Status("업데이트 적용 중...");
            Progress(920, null);
            string applyErr = Apply(todo, deletes);
            if (applyErr != null)
            {
                Report("fail", State.Version, m.Version, m.Build, todo.Count, totalBytes, sw.ElapsedMilliseconds, "적용 실패, 기존 버전으로 복구: " + applyErr);
                result.Outcome = Outcome.Error;
                result.Message = "업데이트 적용에 실패해서 기존 버전으로 복구했습니다.\n" + applyErr;
                result.CanRunExisting = true;
                return result;
            }

            string fromVer = State.Version;
            ApplyStateFromManifest(m);
            CleanupWorkDirs();
            HashCache c2 = new HashCache(Path.Combine(WorkDir, "hash.cache"), false);
            foreach (ManifestFile f in todo) { string l = SafeLocalPath(f.Path); if (File.Exists(l)) c2.Set(l, new FileInfo(l), f.Sha256); }
            c2.Save();

            Progress(1000, FmtSize(totalBytes) + " / " + FmtSize(totalBytes));
            Status("업데이트 완료 (v" + m.Version + ")");
            Form_Info(m.Version, m.Version, State.Notice, m.Version, m.ReleaseNote);
            Report("success", fromVer, m.Version, m.Build, todo.Count + deletes.Count, totalBytes, sw.ElapsedMilliseconds,
                "다운로드 " + todo.Count + "개" + (deletes.Count > 0 ? ", 삭제 " + deletes.Count + "개" : ""));
            return result;
        }

        class LegacyException : Exception { }

        static RunResult Offline(RunResult r, string why)
        {
            Log("서버 연결 실패: " + why);
            if (State.OfflineRun && LaunchTarget() != null)
            {
                Status("서버에 연결할 수 없어 현재 버전으로 실행합니다.");
                QueueReport("offline", "서버 연결 실패: " + why);
                return r;
            }
            r.Outcome = Outcome.Error;
            r.CanRunExisting = false;
            r.Message = LaunchTarget() == null ? "서버에 연결할 수 없고 설치된 프로그램도 없습니다.\n네트워크를 확인한 뒤 다시 시도하세요." : "서버에 연결할 수 없습니다. 네트워크를 확인한 뒤 다시 시도하세요.\n(이 솔루션은 서버 연결 없이 실행할 수 없도록 설정되어 있습니다)";
            return r;
        }

        static void Form_Info(string installed, string latest, string notice, string noteVersion, string releaseNote)
        {
            if (Form == null) return;
            string v = "설치 버전 " + (string.IsNullOrEmpty(installed) ? "없음" : "v" + installed) + "   ·   최신 버전 " + (string.IsNullOrEmpty(latest) ? "-" : "v" + latest);
            Form.SetInfo(State.Name.Length > 0 ? State.Name : null, v, NotesText(notice, noteVersion, releaseNote));
        }

        static void ApplyStateFromManifest(Manifest m)
        {
            State.Version = m.Version; State.Build = m.Build;
            State.Launch = m.Launch; State.LaunchArgs = m.LaunchArgs;
            State.ReleaseNote = m.ReleaseNote;
            if (m.Name.Length > 0) State.Name = m.Name;
            State.Save(StatePath);
        }

        // 저널 형식: 한 줄에 하나, "U|상대경로|기존파일있음(1/0)" 또는 "D|상대경로"
        // 적용 전에 저널을 먼저 쓰고, 적용이 모두 끝나면 지운다. 저널이 남아 있으면 = 적용 도중 중단.
        static string Apply(List<ManifestFile> todo, List<string> deletes)
        {
            List<string> journal = new List<string>();
            foreach (ManifestFile f in todo) journal.Add("U|" + f.Path + "|" + (File.Exists(SafeLocalPath(f.Path)) ? "1" : "0"));
            foreach (string d in deletes) journal.Add("D|" + d + "|1");
            if (Directory.Exists(BackupDir)) Directory.Delete(BackupDir, true);
            Directory.CreateDirectory(BackupDir);
            File.WriteAllLines(JournalPath, journal.ToArray(), Encoding.UTF8);

            try
            {
                foreach (ManifestFile f in todo)
                {
                    string local = SafeLocalPath(f.Path);
                    string bak = Path.Combine(BackupDir, f.Path.Replace('/', Sep));
                    Directory.CreateDirectory(Path.GetDirectoryName(local));
                    if (File.Exists(local)) { Directory.CreateDirectory(Path.GetDirectoryName(bak)); MoveRetry(local, bak); }
                    File.Copy(Path.Combine(StagingDir, f.Sha256.ToLowerInvariant()), local, true);
                    if (new FileInfo(local).Length != f.Size) throw new IOException("적용 결과 크기가 다릅니다: " + f.Path);
                }
                foreach (string d in deletes)
                {
                    string local = SafeLocalPath(d);
                    string bak = Path.Combine(BackupDir, d.Replace('/', Sep));
                    Directory.CreateDirectory(Path.GetDirectoryName(bak));
                    if (File.Exists(local)) MoveRetry(local, bak);
                    RemoveEmptyParents(local);
                    Log("삭제: " + d);
                }
            }
            catch (Exception ex)
            {
                Log("적용 실패: " + ex.Message + " → 복구");
                string err = RestoreFromJournal();
                return ex.Message + (err.Length > 0 ? " / 복구 중 오류: " + err : "");
            }
            File.Delete(JournalPath);   // 여기까지 오면 적용 완료
            foreach (ManifestFile f in todo) Log("업데이트: " + f.Path);
            return null;
        }

        static string RestoreFromJournal()
        {
            StringBuilder errs = new StringBuilder();
            string[] lines = File.Exists(JournalPath) ? File.ReadAllLines(JournalPath, Encoding.UTF8) : new string[0];
            for (int i = lines.Length - 1; i >= 0; i--)
            {
                string[] p = lines[i].Split('|');
                if (p.Length < 3) continue;
                string local = SafeLocalPath(p[1]);
                if (local == null) continue;
                string bak = Path.Combine(BackupDir, p[1].Replace('/', Sep));
                try
                {
                    if (File.Exists(bak))
                    {
                        if (File.Exists(local)) { try { File.Delete(local); } catch { MoveRetry(local, local + "." + DateTime.Now.Ticks + ".upd-old"); } }
                        MoveRetry(bak, local);
                    }
                    else if (p[0] == "U" && p[2] == "0" && File.Exists(local))
                    {
                        File.Delete(local);   // 이번 업데이트로 새로 생긴 파일 제거
                        RemoveEmptyParents(local);
                    }
                }
                catch (Exception ex) { errs.Append(p[1]).Append(": ").Append(ex.Message).Append("; "); }
            }
            try { File.Delete(JournalPath); } catch { }
            Log("복구 완료" + (errs.Length > 0 ? " (오류: " + errs + ")" : ""));
            return errs.ToString();
        }

        // 파일을 지운 뒤 비어 버린 하위 폴더 정리 (bin 폴더 자체는 유지)
        static void RemoveEmptyParents(string file)
        {
            try
            {
                string dir = Path.GetDirectoryName(file);
                string root = BaseDir.TrimEnd(Sep);
                while (dir != null && dir.Length > root.Length && dir.StartsWith(root + Sep, StringComparison.OrdinalIgnoreCase)
                       && Directory.Exists(dir) && Directory.GetFileSystemEntries(dir).Length == 0)
                {
                    Directory.Delete(dir);
                    dir = Path.GetDirectoryName(dir);
                }
            }
            catch { }
        }

        static void MoveRetry(string src, string dst)
        {
            for (int attempt = 0; ; attempt++)
            {
                try
                {
                    if (File.Exists(dst)) File.Delete(dst);
                    File.Move(src, dst);
                    return;
                }
                catch (Exception)
                {
                    if (attempt >= 5) throw;
                    Thread.Sleep(400);
                }
            }
        }

        // 이 bin 폴더의 exe 가 실행 중인지 (런처 자신 제외)
        static string FindRunningProgram()
        {
            int me = Process.GetCurrentProcess().Id;
            string root = BaseDir.TrimEnd(Sep) + Sep;
            foreach (Process p in Process.GetProcesses())
            {
                try
                {
                    if (p.Id == me) continue;
                    string path = p.MainModule.FileName;
                    if (path.StartsWith(root, StringComparison.OrdinalIgnoreCase) && !SamePath(path, SelfPath)) return Path.GetFileName(path);
                }
                catch { }
            }
            return null;
        }

        static void CleanupWorkDirs()
        {
            if (File.Exists(JournalPath)) return;
            try { if (Directory.Exists(BackupDir)) Directory.Delete(BackupDir, true); } catch { }
            try { if (Directory.Exists(StagingDir)) Directory.Delete(StagingDir, true); } catch { }
        }

        static void CleanupOldFiles()
        {
            try
            {
                foreach (string f in Directory.GetFiles(BaseDir, "*.upd-old", SearchOption.AllDirectories))
                    try { File.Delete(f); } catch { }
            }
            catch { }
        }

        // 1.x 런처가 남긴 파일 정리
        static void MigrateLegacy()
        {
            string last = Path.Combine(BaseDir, "updater.last");
            if (File.Exists(last) && State.Launch.Length == 0)
            {
                try { string[] l = File.ReadAllLines(last, Encoding.UTF8); if (l.Length > 0) State.Launch = l[0]; if (l.Length > 1) State.LaunchArgs = l[1]; } catch { }
            }
            foreach (string n in new string[] { "updater.last", "updater.cache", "updater.log" })
                try { string p = Path.Combine(BaseDir, n); if (File.Exists(p)) File.Delete(p); } catch { }
        }

        // ------------------------------------------------------------------
        // 실행
        // ------------------------------------------------------------------
        static string LaunchTarget()
        {
            string exe = Cfg.LaunchExe.Length > 0 ? Cfg.LaunchExe : State.Launch;
            if (exe.Length == 0) return null;
            string full = SafeLocalPath(exe);
            return full != null && File.Exists(full) ? full : null;
        }

        static bool Launch(bool ui)
        {
            string full = LaunchTarget();
            if (full == null)
            {
                string msg = "실행할 프로그램을 찾을 수 없습니다: " + (Cfg.LaunchExe.Length > 0 ? Cfg.LaunchExe : (State.Launch.Length > 0 ? State.Launch : "(미지정)"));
                Log(msg);
                if (Form != null) Form.SetStatus(msg, true); else if (Cfg.ShowWindow) MessageBox.Show(msg, "런처");
                return false;
            }
            string args = ((Cfg.LaunchArgs.Length > 0 ? Cfg.LaunchArgs : State.LaunchArgs) + " " + ExtraArgs).Trim();
            // 프로그램이 "런처가 실행한 것"임을 알 수 있도록 표시 (프로그램이 다시 런처를 부르는 무한 반복 방지)
            //  1) 환경변수 AUTOUPDATER_LAUNCHED=1   2) .update\launch.token 파일 (60초 이내 생성)
            try { File.WriteAllText(Path.Combine(WorkDir, "launch.token"), DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss")); } catch { }
            try
            {
                try
                {
                    ProcessStartInfo psi = new ProcessStartInfo(full, args);
                    psi.WorkingDirectory = Path.GetDirectoryName(full);
                    psi.UseShellExecute = false;
                    psi.EnvironmentVariables["AUTOUPDATER_LAUNCHED"] = "1";
                    Process.Start(psi);
                }
                catch (System.ComponentModel.Win32Exception)
                {
                    // 관리자 권한이 필요한 프로그램(오류 740 등)은 ShellExecute 로 실행 (토큰 파일로 구분)
                    ProcessStartInfo psi = new ProcessStartInfo(full, args);
                    psi.WorkingDirectory = Path.GetDirectoryName(full);
                    psi.UseShellExecute = true;
                    Process.Start(psi);
                }
                Log("실행: " + full + " " + args);
                return true;
            }
            catch (Exception ex)
            {
                Log("실행 실패: " + ex.Message);
                if (Form != null) Form.SetStatus("프로그램 실행 실패: " + ex.Message, true); else if (Cfg.ShowWindow) MessageBox.Show("프로그램 실행 실패: " + ex.Message, "런처");
                return false;
            }
        }

        // ------------------------------------------------------------------
        // 서버 통신 / 보고
        // ------------------------------------------------------------------
        static Dictionary<string, object> BaseRequest()
        {
            Dictionary<string, object> d = new Dictionary<string, object>();
            d["solutionId"] = Cfg.App;
            d["pcId"] = PcId;
            d["pcName"] = Environment.MachineName;
            d["osUser"] = Environment.UserName;
            d["launcherVersion"] = LauncherVersion;
            d["installPath"] = BaseDir;
            if (Cfg.ClientKey.Length > 0) d["clientKey"] = Cfg.ClientKey;
            return d;
        }

        static void Report(string result, string fromVer, string toVer, int toBuild, int files, long bytes, long ms, string message)
        {
            if (Cfg.App.Length == 0 || Cfg.ServerUrl.Length == 0) return;
            if (PcId.Length == 0) PcId = GetPcId();
            Dictionary<string, object> d = BaseRequest();
            d["result"] = result;
            d["fromVersion"] = fromVer;
            d["fromBuild"] = State.Build;
            d["toVersion"] = toVer;
            d["toBuild"] = toBuild;
            d["files"] = files;
            d["bytes"] = bytes;
            d["durationMs"] = ms;
            d["message"] = message;
            d["time"] = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss");
            string json = new JavaScriptSerializer().Serialize(d);
            try { PostJson(Cfg.ServerUrl.TrimEnd('/') + "/api/v1/launcher/report.php", d, 5); }
            catch (Exception ex)
            {
                Log("결과 보고 실패 (다음 실행 때 재전송): " + ex.Message);
                if (result != "uptodate") try { File.AppendAllText(ReportsPath, json + "\n", Encoding.UTF8); } catch { }
            }
        }

        static void QueueReport(string result, string message)
        {
            if (Cfg.App.Length == 0) return;
            Dictionary<string, object> d = BaseRequest();
            d["result"] = result; d["fromVersion"] = State.Version; d["toVersion"] = State.Version; d["toBuild"] = State.Build; d["fromBuild"] = State.Build;
            d["message"] = message + " (" + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + ")";
            try { File.AppendAllText(ReportsPath, new JavaScriptSerializer().Serialize(d) + "\n", Encoding.UTF8); } catch { }
        }

        static void FlushReports()
        {
            if (!File.Exists(ReportsPath) || Cfg.ServerUrl.Length == 0) return;
            string[] lines;
            try { lines = File.ReadAllLines(ReportsPath, Encoding.UTF8); File.Delete(ReportsPath); } catch { return; }
            JavaScriptSerializer js = new JavaScriptSerializer();
            List<string> left = new List<string>();
            foreach (string line in lines)
            {
                if (line.Trim().Length == 0) continue;
                try { PostJson(Cfg.ServerUrl.TrimEnd('/') + "/api/v1/launcher/report.php", js.Deserialize<Dictionary<string, object>>(line), 5); }
                catch { left.Add(line); }
            }
            if (left.Count > 0) try { File.WriteAllLines(ReportsPath, left.Count > 50 ? left.GetRange(left.Count - 50, 50).ToArray() : left.ToArray(), Encoding.UTF8); } catch { }
        }

        static Dictionary<string, object> PostJson(string url, Dictionary<string, object> body, int timeoutSec)
        {
            byte[] data = Encoding.UTF8.GetBytes(new JavaScriptSerializer().Serialize(body));
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.Method = "POST";
            req.ContentType = "application/json; charset=utf-8";
            req.Timeout = timeoutSec * 1000;
            req.UserAgent = "AutoUpdater/" + LauncherVersion;
            if (Cfg.ClientKey.Length > 0) req.Headers["X-Client-Key"] = Cfg.ClientKey;
            req.ContentLength = data.Length;
            using (Stream s = req.GetRequestStream()) s.Write(data, 0, data.Length);
            using (WebResponse resp = req.GetResponse())
            using (StreamReader r = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
            {
                Dictionary<string, object> d = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(r.ReadToEnd());
                if (d == null) throw new Exception("서버 응답을 해석할 수 없습니다");
                return d;
            }
        }

        // 오류 응답 본문의 error 메시지
        static string ReadError(WebException ex)
        {
            try
            {
                if (ex.Response == null) return "";
                using (StreamReader r = new StreamReader(ex.Response.GetResponseStream(), Encoding.UTF8))
                {
                    Dictionary<string, object> d = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(r.ReadToEnd());
                    return d != null ? Str(d, "error") : "";
                }
            }
            catch { return ""; }
        }

        static string GetString(string url, int timeoutSec)
        {
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.Timeout = timeoutSec * 1000;
            req.UserAgent = "AutoUpdater/" + LauncherVersion;
            req.Headers.Add("Cache-Control", "no-cache");
            using (WebResponse resp = req.GetResponse())
            using (StreamReader r = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
                return r.ReadToEnd();
        }

        static void Download(string url, string dest, ManifestFile f, Action<long> progress)
        {
            string tmp = dest + ".part";
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.Timeout = Cfg.TimeoutSec * 1000;
            req.ReadWriteTimeout = Math.Max(60, Cfg.TimeoutSec) * 1000;
            req.UserAgent = "AutoUpdater/" + LauncherVersion;
            string hash;
            long got = 0;
            using (SHA256 sha = SHA256.Create())
            {
                using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
                using (Stream src = resp.GetResponseStream())
                using (FileStream dst = new FileStream(tmp, FileMode.Create, FileAccess.Write))
                {
                    byte[] buf = new byte[81920];
                    int n;
                    while ((n = src.Read(buf, 0, buf.Length)) > 0)
                    {
                        dst.Write(buf, 0, n);
                        sha.TransformBlock(buf, 0, n, null, 0);
                        got += n;
                        progress(got);
                    }
                    sha.TransformFinalBlock(new byte[0], 0, 0);
                    hash = ToHex(sha.Hash);
                }
            }
            if (got != f.Size || !string.Equals(hash, f.Sha256, StringComparison.OrdinalIgnoreCase))
            {
                try { File.Delete(tmp); } catch { }
                throw new Exception("파일 검증 실패 (해시 불일치)");
            }
            if (File.Exists(dest)) File.Delete(dest);
            File.Move(tmp, dest);
        }

        // ------------------------------------------------------------------
        // 유틸
        // ------------------------------------------------------------------
        static Manifest ParseManifest(string json)
        {
            JavaScriptSerializer js = new JavaScriptSerializer();
            js.MaxJsonLength = int.MaxValue;
            Dictionary<string, object> root = js.Deserialize<Dictionary<string, object>>(json);
            Manifest m = new Manifest();
            m.Name = Str(root, "name");
            m.Version = Str(root, "version");
            int b; m.Build = int.TryParse(Str(root, "build"), out b) ? b : (int.TryParse(m.Version, out b) ? b : 0);
            m.Launch = Str(root, "launch");
            m.LaunchArgs = Str(root, "launchArgs");
            m.Notice = Str(root, "notice");
            m.ReleaseNote = Str(root, "releaseNote");
            m.Active = Bool(root, "active", true);
            m.OfflineRun = Bool(root, "offlineRun", true);
            m.Signed = Str(root, "signed");
            m.Signature = Str(root, "signature");
            ReadFileList(root, m);
            return m;
        }

        static void ReadFileList(Dictionary<string, object> root, Manifest m)
        {
            m.Files.Clear(); m.Delete.Clear();
            object files;
            if (root.TryGetValue("files", out files) && files is ArrayList)
                foreach (object o in (ArrayList)files)
                {
                    Dictionary<string, object> d = o as Dictionary<string, object>;
                    if (d == null) continue;
                    ManifestFile f = new ManifestFile();
                    f.Path = Str(d, "path");
                    f.Sha256 = Str(d, "sha256").Length > 0 ? Str(d, "sha256") : Str(d, "hash");
                    f.Size = Convert.ToInt64(d.ContainsKey("size") ? d["size"] : 0);
                    if (f.Path.Length > 0 && f.Sha256.Length == 64) m.Files.Add(f);
                }
            object del;
            if (root.TryGetValue("delete", out del) && del is ArrayList)
                foreach (object o in (ArrayList)del) if (o != null) m.Delete.Add(o.ToString());
        }

        // ------------------------------------------------------------------
        // 배포 전자서명 확인
        //  updater.ini 의 SignKey(= base64(modulus).base64(exponent)) 로 manifest 의 signed 원문 서명을 확인하고,
        //  맞으면 파일 목록/실행 설정/버전은 서명된 원문 값만 사용한다 (서버가 바꿔치기한 바깥 값은 무시).
        //  다른 솔루션의 서명 원문, 지금보다 낮은 빌드(옛 버전 되돌리기 공격)도 거부.
        // ------------------------------------------------------------------
        static void VerifyManifest(Manifest m)
        {
            if (m.Signed.Length == 0 || m.Signature.Length == 0)
                throw new Exception("서명이 없는 업데이트라서 설치하지 않습니다. (관리 사이트에서 전자서명 후 배포하세요)");
            if (!VerifySignature(Cfg.SignKey, m.Signed, m.Signature))
                throw new Exception("업데이트 서명이 맞지 않아 설치하지 않습니다. 서버가 변조되었을 수 있으니 관리자에게 알리세요.");
            JavaScriptSerializer js = new JavaScriptSerializer();
            js.MaxJsonLength = int.MaxValue;
            Dictionary<string, object> p = js.Deserialize<Dictionary<string, object>>(m.Signed);
            if (Cfg.App.Length > 0 && !string.Equals(Str(p, "app"), Cfg.App, StringComparison.OrdinalIgnoreCase))
                throw new Exception("다른 솔루션(" + Str(p, "app") + ")의 서명이라 설치하지 않습니다.");
            int build;
            if (!int.TryParse(Str(p, "build"), out build)) throw new Exception("서명 내용이 잘못되었습니다.");
            if (build < State.Build)
                throw new Exception("서버 버전(빌드 " + build + ")이 설치된 버전(빌드 " + State.Build + ")보다 낮아 설치하지 않습니다. (옛 버전 되돌리기 차단)");
            m.Build = build;
            m.Version = Str(p, "version");
            m.Launch = Str(p, "launch");
            m.LaunchArgs = Str(p, "launchArgs");
            ReadFileList(p, m);
            Log("전자서명 확인 완료 (빌드 " + build + ")");
        }

        // SignKey 에 키를 쉼표로 여러 개 넣을 수 있음 (키 교체 기간에 옛 키 + 새 키)
        public static bool VerifySignature(string keys, string payload, string sigB64)
        {
            foreach (string key in keys.Split(','))
                if (key.Trim().Length > 0 && VerifyOne(key, payload, sigB64)) return true;
            return false;
        }

        static bool VerifyOne(string key, string payload, string sigB64)
        {
            try
            {
                string[] k = key.Trim().Split('.');
                if (k.Length != 2) return false;
                RSAParameters rp = new RSAParameters();
                rp.Modulus = Convert.FromBase64String(k[0]);
                rp.Exponent = Convert.FromBase64String(k[1]);
                if (rp.Modulus.Length < 256) return false;   // 2048비트 미만 거부
                using (RSACryptoServiceProvider rsa = new RSACryptoServiceProvider())
                {
                    rsa.PersistKeyInCsp = false;
                    rsa.ImportParameters(rp);
                    return rsa.VerifyData(Encoding.UTF8.GetBytes(payload), "SHA256", Convert.FromBase64String(sigB64));
                }
            }
            catch (Exception ex) { Log("서명 확인 오류: " + ex.Message); return false; }
        }

        static string Str(Dictionary<string, object> d, string key)
        {
            object v;
            return d != null && d.TryGetValue(key, out v) && v != null ? v.ToString() : "";
        }

        static bool Bool(Dictionary<string, object> d, string key, bool def)
        {
            object v;
            if (d == null || !d.TryGetValue(key, out v) || v == null) return def;
            if (v is bool) return (bool)v;
            return Ini.IsTrue(v.ToString());
        }

        // 서버가 준 경로가 bin 폴더 밖이나 런처 작업 폴더를 가리키지 못하도록
        static string SafeLocalPath(string rel)
        {
            if (string.IsNullOrEmpty(rel)) return null;
            rel = rel.Replace('/', Sep).Replace('\\', Sep).TrimStart(Sep);
            if (rel.Contains(":") || rel.Contains("..")) return null;
            if (rel.StartsWith(".update", StringComparison.OrdinalIgnoreCase)) return null;
            string full = Path.GetFullPath(Path.Combine(BaseDir, rel));
            string root = BaseDir.TrimEnd(Sep) + Sep;
            return full.StartsWith(root, StringComparison.OrdinalIgnoreCase) ? full : null;
        }

        static bool SamePath(string a, string b)
        {
            try { return string.Equals(Path.GetFullPath(a), Path.GetFullPath(b), StringComparison.OrdinalIgnoreCase); } catch { return false; }
        }

        // PC 고유 ID: 여러 솔루션이 같은 ID 를 쓰도록 ProgramData 에 저장
        static string GetPcId()
        {
            string[] places = {
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), Path.Combine("AutoUpdater", "pcid.txt")),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), Path.Combine("AutoUpdater", "pcid.txt")),
                Path.Combine(WorkDir, "pcid.txt")
            };
            foreach (string p in places)
            {
                try { if (File.Exists(p)) { string id = File.ReadAllText(p).Trim(); if (id.Length >= 4) return id; } } catch { }
            }
            string nid = "PC-" + Guid.NewGuid().ToString("N").Substring(0, 16).ToUpperInvariant();
            foreach (string p in places)
            {
                try { Directory.CreateDirectory(Path.GetDirectoryName(p)); File.WriteAllText(p, nid); return nid; } catch { }
            }
            return nid;
        }

        public static string FileSha256(string path)
        {
            using (SHA256 sha = SHA256.Create())
            using (FileStream fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
                return ToHex(sha.ComputeHash(fs));
        }

        static string Sha256Text(string s)
        {
            using (SHA256 sha = SHA256.Create()) return ToHex(sha.ComputeHash(Encoding.UTF8.GetBytes(s)));
        }

        static string ToHex(byte[] b)
        {
            StringBuilder sb = new StringBuilder(b.Length * 2);
            foreach (byte x in b) sb.Append(x.ToString("x2"));
            return sb.ToString();
        }

        static string FmtSize(long b)
        {
            if (b >= 1073741824) return (b / 1073741824.0).ToString("0.00") + " GB";
            if (b >= 1048576) return (b / 1048576.0).ToString("0.0") + " MB";
            if (b >= 1024) return (b / 1024.0).ToString("0.0") + " KB";
            return b + " B";
        }

        static string JoinArgs(string[] args)
        {
            StringBuilder sb = new StringBuilder();
            foreach (string a in args)
            {
                if (sb.Length > 0) sb.Append(' ');
                if (a.IndexOfAny(new char[] { ' ', '\t', '"' }) >= 0) sb.Append('"').Append(a.Replace("\"", "\\\"")).Append('"');
                else sb.Append(a);
            }
            return sb.ToString();
        }

        static readonly object LogLock = new object();
        public static void Log(string msg)
        {
            lock (LogLock)
            {
                try { File.AppendAllText(LogPath, DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "  " + msg + "\r\n", Encoding.UTF8); } catch { }
            }
        }

        static void TrimLog()
        {
            try { FileInfo fi = new FileInfo(LogPath); if (fi.Exists && fi.Length > 1024 * 1024) File.Delete(LogPath); } catch { }
        }
    }

    // 크기+수정시각이 같으면 저장된 해시를 재사용 (매번 전체 해시 계산 방지)
    class HashCache
    {
        string _path;
        bool _ignore;
        Dictionary<string, string> _map = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        bool _dirty;

        public HashCache(string path, bool ignoreExisting)
        {
            _path = path; _ignore = ignoreExisting;
            try
            {
                if (!ignoreExisting && File.Exists(path))
                    foreach (string line in File.ReadAllLines(path, Encoding.UTF8))
                    {
                        int t = line.IndexOf('\t');
                        if (t > 0) _map[line.Substring(0, t)] = line.Substring(t + 1);
                    }
            }
            catch { }
        }

        static string Key(string file, FileInfo fi) { return file + "|" + fi.Length + "|" + fi.LastWriteTimeUtc.Ticks; }

        public string GetHash(string file, FileInfo fi)
        {
            string k = Key(file, fi), h;
            if (_map.TryGetValue(k, out h)) return h;
            h = Program.FileSha256(file);
            _map[k] = h; _dirty = true;
            return h;
        }

        public void Set(string file, FileInfo fi, string hash) { _map[Key(file, fi)] = hash.ToLowerInvariant(); _dirty = true; }

        public void Save()
        {
            if (!_dirty) return;
            try
            {
                Dictionary<string, string> merged = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                if (_ignore) { HashCache old = new HashCache(_path, false); foreach (KeyValuePair<string, string> kv in old._map) merged[kv.Key] = kv.Value; }
                foreach (KeyValuePair<string, string> kv in _map) merged[kv.Key] = kv.Value;
                List<string> lines = new List<string>();
                foreach (KeyValuePair<string, string> kv in merged)
                {
                    int bar = kv.Key.IndexOf('|');
                    string file = bar > 0 ? kv.Key.Substring(0, bar) : kv.Key;
                    if (!File.Exists(file) || kv.Key != Key(file, new FileInfo(file))) continue;
                    lines.Add(kv.Key + "\t" + kv.Value);
                }
                File.WriteAllLines(_path, lines.ToArray(), Encoding.UTF8);
            }
            catch { }
        }
    }
}
