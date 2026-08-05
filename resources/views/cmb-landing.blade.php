<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMB CORE Marketing Auto — Tự động hoá pipeline video marketing</title>
    <meta name="description" content="Tìm → tải → chỉnh sửa → lồng tiếng AI → đăng tải tự động lên TikTok, Facebook, YouTube. Một phần mềm, chạy 24/7, không cần đụng tay.">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Archivo:wght@500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --ink: #0b0d12;
            --panel: #14171f;
            --panel-2: #1a1e28;
            --line: #262b36;
            --signal-a: #6ef3d6;
            --signal-a-dim: #6ef3d633;
            --signal-b: #ff6b4a;
            --signal-b-dim: #ff6b4a33;
            --paper: #edeff3;
            --paper-dim: #9aa1af;
            --paper-dimmer: #5c6270;
            --radius: 14px;
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            * { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
        }

        body {
            margin: 0;
            background: var(--ink);
            color: var(--paper);
            font-family: 'Archivo', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        .mono {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
        }

        a { color: inherit; }

        .wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 28px;
        }

        /* ---------- Nav ---------- */
        nav.top {
            position: sticky;
            top: 0;
            z-index: 40;
            background: rgba(11, 13, 18, 0.82);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--line);
        }
        nav.top .wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 68px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            font-size: 17px;
            letter-spacing: 0.2px;
            text-decoration: none;
        }
        .brand .dot {
            width: 9px; height: 9px; border-radius: 50%;
            background: var(--signal-a);
            box-shadow: 0 0 0 4px var(--signal-a-dim);
        }
        .nav-links {
            display: flex;
            align-items: center;
            gap: 30px;
            font-size: 14px;
            color: var(--paper-dim);
        }
        .nav-links a { text-decoration: none; transition: color .15s ease; }
        .nav-links a:hover { color: var(--paper); }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            border: 1px solid transparent;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
            cursor: pointer;
        }
        .btn:focus-visible {
            outline: 2px solid var(--signal-a);
            outline-offset: 2px;
        }
        .btn-signal {
            background: var(--signal-a);
            color: #06231c;
        }
        .btn-signal:hover { transform: translateY(-1px); box-shadow: 0 8px 24px var(--signal-a-dim); }
        .btn-ghost {
            border-color: var(--line);
            color: var(--paper);
            background: transparent;
        }
        .btn-ghost:hover { border-color: var(--paper-dimmer); background: var(--panel); }
        .btn-disabled {
            border-color: var(--line);
            color: var(--paper-dim);
            background: transparent;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* ---------- Hero ---------- */
        header.hero { padding: 96px 0 40px; position: relative; }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 12.5px;
            letter-spacing: 0.06em;
            color: var(--signal-a);
            background: var(--signal-a-dim);
            border: 1px solid #6ef3d64d;
            padding: 6px 12px 6px 10px;
            border-radius: 999px;
            margin-bottom: 28px;
        }
        .eyebrow .rec {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--signal-b);
            animation: blink 1.6s ease-in-out infinite;
        }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }

        h1.headline {
            font-size: clamp(38px, 6vw, 68px);
            line-height: 1.04;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0 0 24px;
            max-width: 15ch;
        }
        h1.headline em {
            font-style: normal;
            background: linear-gradient(90deg, var(--signal-a), #9df8e6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        p.sub {
            font-size: clamp(16px, 2vw, 19px);
            line-height: 1.65;
            color: var(--paper-dim);
            max-width: 56ch;
            margin: 0 0 36px;
        }
        p.sub strong { color: var(--paper); font-weight: 600; }

        .cta-row { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-bottom: 20px; }
        .btn-lg { padding: 15px 26px; font-size: 15px; }
        .version-line {
            font-size: 12.5px;
            color: var(--paper-dimmer);
            margin-bottom: 56px;
        }
        .version-line code { color: var(--paper-dim); }

        /* ---------- Timeline signature ---------- */
        .timeline-rail {
            position: relative;
            padding: 28px 0 4px;
        }
        .timeline-track {
            position: relative;
            height: 3px;
            background: var(--line);
            border-radius: 3px;
            margin: 0 0 22px;
        }
        .timeline-progress {
            position: absolute;
            top: 0; left: 0; height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--signal-b), var(--signal-a));
            border-radius: 3px;
            animation: sweep 5.4s cubic-bezier(.65,0,.35,1) infinite;
        }
        @keyframes sweep {
            0%   { width: 0%; }
            85%  { width: 100%; }
            100% { width: 100%; }
        }
        .timeline-stages {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
        }
        .stage {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        .stage .tick {
            width: 11px; height: 11px;
            border-radius: 50%;
            background: var(--panel);
            border: 2px solid var(--paper-dimmer);
            margin-left: -1px;
        }
        .stage .num {
            font-size: 11px;
            color: var(--paper-dimmer);
        }
        .stage .label {
            font-size: 13px;
            font-weight: 600;
            color: var(--paper-dim);
            line-height: 1.3;
        }
        @media (max-width: 860px) {
            .timeline-stages { grid-template-columns: repeat(3, 1fr); row-gap: 24px; }
        }
        @media (max-width: 520px) {
            .timeline-stages { grid-template-columns: repeat(2, 1fr); }
        }

        .stat-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            background: var(--line);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
            margin-top: 64px;
        }
        .stat {
            background: var(--panel);
            padding: 22px 20px;
        }
        .stat .n {
            font-family: 'JetBrains Mono', monospace;
            font-size: 26px;
            font-weight: 700;
            color: var(--signal-a);
        }
        .stat .l {
            font-size: 12.5px;
            color: var(--paper-dim);
            margin-top: 4px;
        }
        @media (max-width: 700px) {
            .stat-row { grid-template-columns: repeat(2, 1fr); }
        }

        /* ---------- Sections ---------- */
        section { padding: 100px 0; }
        section.tight { padding: 60px 0 100px; }
        .section-head { max-width: 640px; margin: 0 0 56px; }
        .section-head .eyebrow-line {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12.5px;
            color: var(--signal-a);
            letter-spacing: 0.08em;
            margin-bottom: 14px;
        }
        .section-head h2 {
            font-size: clamp(28px, 4vw, 40px);
            font-weight: 800;
            letter-spacing: -0.01em;
            margin: 0 0 14px;
        }
        .section-head p {
            color: var(--paper-dim);
            font-size: 16px;
            line-height: 1.6;
            margin: 0;
        }

        /* Feature grid */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1px;
            background: var(--line);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .feature {
            background: var(--panel);
            padding: 30px 26px;
            transition: background .2s ease;
        }
        .feature:hover { background: var(--panel-2); }
        .feature .tag {
            display: inline-block;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: var(--signal-a);
            background: var(--signal-a-dim);
            padding: 3px 8px;
            border-radius: 5px;
            margin-bottom: 14px;
        }
        .feature h3 {
            font-size: 17px;
            font-weight: 700;
            margin: 0 0 8px;
        }
        .feature p {
            font-size: 14px;
            line-height: 1.6;
            color: var(--paper-dim);
            margin: 0;
        }
        @media (max-width: 860px) { .feature-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .feature-grid { grid-template-columns: 1fr; } }

        /* Why-choose pillars */
        .pillar-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 22px;
        }
        .pillar {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 26px 22px;
            background: linear-gradient(180deg, var(--panel), transparent);
        }
        .pillar .glyph {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: var(--signal-b-dim);
            border: 1px solid #ff6b4a4d;
            display: flex; align-items: center; justify-content: center;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            color: var(--signal-b);
            margin-bottom: 18px;
        }
        .pillar h3 { font-size: 16px; font-weight: 700; margin: 0 0 8px; }
        .pillar p { font-size: 13.5px; line-height: 1.6; color: var(--paper-dim); margin: 0; }
        @media (max-width: 860px) { .pillar-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .pillar-row { grid-template-columns: 1fr; } }

        /* CTA band */
        .cta-band {
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 56px;
            background:
                radial-gradient(600px 200px at 15% 0%, var(--signal-a-dim), transparent 60%),
                radial-gradient(600px 200px at 85% 100%, var(--signal-b-dim), transparent 60%),
                var(--panel);
            text-align: center;
        }
        .cta-band h2 { font-size: clamp(24px, 3.4vw, 34px); font-weight: 800; margin: 0 0 14px; }
        .cta-band p { color: var(--paper-dim); font-size: 15.5px; margin: 0 0 32px; }
        .cta-band .cta-row { justify-content: center; }

        /* Footer */
        footer {
            border-top: 1px solid var(--line);
            padding: 56px 0 32px;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }
        footer h3 { font-size: 16px; margin: 0 0 10px; font-weight: 800; }
        footer p { font-size: 13.5px; color: var(--paper-dim); line-height: 1.6; max-width: 34ch; }
        footer h5 { font-size: 12.5px; color: var(--paper-dimmer); text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 14px; }
        footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
        footer ul a { text-decoration: none; font-size: 13.5px; color: var(--paper-dim); transition: color .15s ease; }
        footer ul a:hover { color: var(--paper); }
        .footer-bottom {
            border-top: 1px solid var(--line);
            padding-top: 24px;
            font-size: 12.5px;
            color: var(--paper-dimmer);
            display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;
        }
        @media (max-width: 760px) { .footer-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <nav class="top">
        <div class="wrap">
            <a href="#" class="brand"><span class="dot"></span> CMB CORE <span class="mono" style="color:var(--paper-dimmer);font-weight:500;">/ marketing</span></a>
            <div class="nav-links">
                <a href="#pipeline">Quy trình</a>
                <a href="#features">Tính năng</a>
                <a href="#why">Vì sao chọn</a>
                <a href="https://mkt.cmbcore.com/login" class="btn btn-ghost" target="_blank" rel="noopener">Đăng nhập</a>
            </div>
        </div>
    </nav>

    <header class="hero">
        <div class="wrap">
            <span class="eyebrow mono"><span class="rec"></span> ĐANG XỬ LÝ — 24/7 TỰ ĐỘNG</span>

            <h1 class="headline">Tự động hoá toàn bộ <em>pipeline video</em> marketing.</h1>

            <p class="sub">
                Một phần mềm thực hiện trọn quy trình <strong>tìm → tải → chỉnh sửa → lồng tiếng AI → đăng tải</strong>
                trên TikTok, Facebook, YouTube. Chạy hàng loạt, chạy ngầm, chạy 24/7 — không cần bạn ngồi canh.
            </p>

            <div class="cta-row">
                @if($latestTool)
                    <a href="{{ $latestTool->download_url }}" class="btn btn-signal btn-lg">Tải Ngay — v{{ $latestTool->version }} ▸</a>
                @else
                    <span class="btn btn-disabled btn-lg" aria-disabled="true">Sắp ra mắt</span>
                @endif
                <a href="https://mkt.cmbcore.com/register" class="btn btn-ghost btn-lg" target="_blank" rel="noopener">Tạo tài khoản</a>
            </div>
            <div class="version-line mono">Yêu cầu tài khoản để sử dụng · gói dịch vụ gắn liền với tài khoản</div>

            <div class="timeline-rail">
                <div class="timeline-track"><div class="timeline-progress"></div></div>
                <div class="timeline-stages">
                    <div class="stage"><span class="tick"></span><span class="num mono">01</span><span class="label">Đăng nhập</span></div>
                    <div class="stage"><span class="tick"></span><span class="num mono">02</span><span class="label">Thêm TK mạng xã hội</span></div>
                    <div class="stage"><span class="tick"></span><span class="num mono">03</span><span class="label">Tìm video trending</span></div>
                    <div class="stage"><span class="tick"></span><span class="num mono">04</span><span class="label">Tải hàng loạt</span></div>
                    <div class="stage"><span class="tick"></span><span class="num mono">05</span><span class="label">Chỉnh sửa &amp; lồng tiếng</span></div>
                    <div class="stage"><span class="tick"></span><span class="num mono">06</span><span class="label">Đăng &amp; theo dõi</span></div>
                </div>
            </div>

            <div class="stat-row">
                <div class="stat"><div class="n mono">3</div><div class="l">Nền tảng: TikTok / Facebook / YouTube</div></div>
                <div class="stat"><div class="n mono">40+</div><div class="l">Bộ lọc màu cinematic</div></div>
                <div class="stat"><div class="n mono">100+</div><div class="l">Video xử lý mỗi ngày</div></div>
                <div class="stat"><div class="n mono">24/7</div><div class="l">Vận hành tự động, không cần canh máy</div></div>
            </div>
        </div>
    </header>

    <section id="pipeline">
        <div class="wrap">
            <div class="section-head">
                <div class="eyebrow-line">// QUY TRÌNH VẬN HÀNH</div>
                <h2>6 bước, từ đăng nhập đến đăng bài — không có bước nào bạn phải tự tay làm lại.</h2>
                <p>Mỗi giai đoạn nối tiếp giai đoạn trước tự động. Bạn thiết lập một lần, phần mềm lặp lại vô hạn.</p>
            </div>
            <div class="feature-grid">
                <div class="feature">
                    <span class="tag mono">01—02</span>
                    <h3>Đăng nhập &amp; kết nối tài khoản</h3>
                    <p>Thêm tài khoản TikTok / Facebook / YouTube thủ công hoặc import hàng loạt từ file. Kiểm tra trạng thái Live trước khi chạy chiến dịch.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">03—04</span>
                    <h3>Tìm &amp; tải video trending</h3>
                    <p>Tìm theo từ khóa, theo kênh, hoặc dán URL trực tiếp. Tải song song nhiều luồng, tự nhận diện nền tảng, xuất sẵn danh sách cho bước sau.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">05</span>
                    <h3>Chỉnh sửa &amp; lồng tiếng AI</h3>
                    <p>Lật hình, đổi filter, watermark, đổi nhạc, đổi tốc độ, phụ đề tự động — mỗi video ra một bản độc bản. Dịch giọng nói sang 50+ ngôn ngữ khi cần.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">06</span>
                    <h3>Đăng tải &amp; lên lịch</h3>
                    <p>Upload trực tiếp lên Page / Kênh / Profile, giãn cách thời gian mô phỏng hành vi tự nhiên, tự thử lại khi lỗi.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">06</span>
                    <h3>Theo dõi chiến dịch</h3>
                    <p>Dashboard tổng quan: số chiến dịch, video, tài khoản, tiến độ từng job theo thời gian thực. Dừng / tiếp tục bất cứ lúc nào.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">∞</span>
                    <h3>Chạy nền vô hạn</h3>
                    <p>Thu nhỏ xuống khay hệ thống, phần mềm vẫn chạy. Cập nhật tự động (OTA) — luôn ở phiên bản mới nhất.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="tight">
        <div class="wrap">
            <div class="section-head">
                <div class="eyebrow-line">// ĐẦY ĐỦ TÍNH NĂNG</div>
                <h2>9 module, bao phủ toàn bộ quy trình marketing video.</h2>
                <p>Không phải ghép nhiều công cụ rời rạc — mọi thứ nằm trong một phần mềm duy nhất.</p>
            </div>
            <div class="feature-grid">
                <div class="feature">
                    <span class="tag mono">search</span>
                    <h3>Tìm kiếm video thông minh</h3>
                    <p>Tìm theo từ khóa trên YouTube &amp; TikTok, lấy video từ kênh cụ thể, phân loại Shorts / video dài, xem trước lượt xem và thời lượng.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">download</span>
                    <h3>Tải video hàng loạt</h3>
                    <p>Import danh sách URL từ file, tải song song nhiều luồng tuỳ chỉnh, theo dõi tiến độ từng video trực tiếp trên giao diện.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">edit</span>
                    <h3>Chỉnh sửa video hàng loạt</h3>
                    <p>40+ bộ lọc cinematic, watermark chữ/ảnh, đổi nhạc nền, tốc độ 0.5x–2.0x, phụ đề tự động — random hoá toàn bộ thông số cho mỗi video.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">ai-dub</span>
                    <h3>Chuyển đổi ngôn ngữ — AI</h3>
                    <p>STT → Dịch AI → TTS khớp từng phân đoạn. Giọng đọc tự nhiên, nam/nữ, 50+ ngôn ngữ. Xử lý cả thư mục chỉ với một lần bấm.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">upload</span>
                    <h3>Upload đa nền tảng</h3>
                    <p>Facebook (Page, Post, Reels), YouTube (tiêu đề/mô tả tự động), TikTok (mô tả &amp; hashtag) — lên lịch, giãn cách, tự thử lại khi lỗi.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">accounts</span>
                    <h3>Quản lý tài khoản</h3>
                    <p>Đăng nhập tự động, hỗ trợ xác thực 2 bước, import hàng loạt, kiểm tra tài khoản còn hoạt động (Check Live) trước mỗi chiến dịch.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">campaign</span>
                    <h3>Quản lý chiến dịch</h3>
                    <p>Chọn nền tảng → tài khoản → video, lên lịch tự động (cron) — đặt một lần, quên luôn. Theo dõi trạng thái real-time.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">dashboard</span>
                    <h3>Dashboard tổng quan</h3>
                    <p>Tổng số chiến dịch, video, tài khoản đã thêm. Chiến dịch đang chạy, video đang upload — dừng/tiếp tục bất cứ lúc nào.</p>
                </div>
                <div class="feature">
                    <span class="tag mono">system</span>
                    <h3>Cài đặt &amp; hệ thống</h3>
                    <p>Cập nhật OTA tự động, mã hoá thông tin tài khoản, chạy nền trong khay hệ thống mà không gián đoạn công việc khác.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="why">
        <div class="wrap">
            <div class="section-head">
                <div class="eyebrow-line">// VÌ SAO CHỌN CMB CORE</div>
                <h2>Biến một người thành cả một đội ngũ marketing.</h2>
                <p>Tiết kiệm hàng trăm giờ thao tác thủ công mỗi tháng.</p>
            </div>
            <div class="pillar-row">
                <div class="pillar">
                    <div class="glyph">A/B</div>
                    <h3>Biến đổi đa dạng</h3>
                    <p>Render AI tự động tinh chỉnh hiệu ứng, tốc độ, bộ lọc, âm thanh — tạo video độc bản từ một nguồn duy nhất.</p>
                </div>
                <div class="pillar">
                    <div class="glyph">×N</div>
                    <h3>Hiệu suất tối đa</h3>
                    <p>Đa luồng thực sự: tải, chỉnh sửa, đăng tải song song — không nghẽn, không chờ nhau.</p>
                </div>
                <div class="pillar">
                    <div class="glyph">⏱</div>
                    <h3>Vận hành tự động</h3>
                    <p>Thiết lập kịch bản một lần, hệ thống tự đăng theo khung giờ vàng để tối đa hoá lượt tiếp cận.</p>
                </div>
                <div class="pillar">
                    <div class="glyph">◆</div>
                    <h3>Bảo mật toàn diện</h3>
                    <p>Phiên đăng nhập tài khoản được lưu trữ mã hoá, tách biệt hoàn toàn khỏi máy chủ ngoài.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="tight">
        <div class="wrap">
            <div class="cta-band">
                <h2>Sẵn sàng bỏ qua thao tác thủ công?</h2>
                <p>Tạo tài khoản, tải phần mềm, và để pipeline chạy phần còn lại.</p>
                <div class="cta-row">
                    @if($latestTool)
                        <a href="{{ $latestTool->download_url }}" class="btn btn-signal btn-lg">Tải Ngay — v{{ $latestTool->version }} ▸</a>
                    @else
                        <span class="btn btn-disabled btn-lg" aria-disabled="true">Sắp ra mắt</span>
                    @endif
                    <a href="https://mkt.cmbcore.com/register" class="btn btn-ghost btn-lg" target="_blank" rel="noopener">Tạo tài khoản miễn phí</a>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="wrap">
            <div class="footer-grid">
                <div>
                    <h3><span class="mono" style="color:var(--signal-a);">●</span> CMB CORE</h3>
                    <p>Giải pháp phần mềm tự động hoá marketing video đa nền tảng hàng đầu Việt Nam.</p>
                </div>
                <div>
                    <h5>Sản phẩm</h5>
                    <ul>
                        <li><a href="#pipeline">Quy trình</a></li>
                        <li><a href="#features">Tính năng</a></li>
                        <li><a href="https://mkt.cmbcore.com/register" target="_blank" rel="noopener">Tạo tài khoản</a></li>
                    </ul>
                </div>
                <div>
                    <h5>Liên hệ</h5>
                    <ul>
                        <li><a href="mailto:congminhbui999@gmail.com">congminhbui999@gmail.com</a></li>
                        <li><a href="https://cimo.vn">cimo.vn</a></li>
                        <li><a href="https://facebook.com/congminher">Facebook</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© {{ date('Y') }} CMB CORE Company. All rights reserved.</span>
                <span class="mono">v4.2.1</span>
            </div>
        </div>
    </footer>

</body>
</html>
