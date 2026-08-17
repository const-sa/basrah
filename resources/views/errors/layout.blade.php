<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') — @yield('title')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --accent: @yield('accent', '#10b981');
            --ink: #0f172a;
            --muted: #64748b;
        }
        html, body { height: 100%; }
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background:
                radial-gradient(900px 500px at 50% -10%, color-mix(in srgb, var(--accent) 12%, #fff) 0%, transparent 60%),
                #fbfcfe;
            color: var(--ink); padding: 24px; overflow: hidden; position: relative;
        }

        /* نجوم لامعة خفيفة في الخلفية */
        .stars { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
        .star { position: absolute; border-radius: 50%; background: var(--accent); animation: twinkle 3s ease-in-out infinite; }
        @keyframes twinkle { 0%,100% { opacity: .15; transform: scale(.7); } 50% { opacity: .7; transform: scale(1); } }

        .wrap { position: relative; z-index: 1; width: 100%; max-width: 560px; text-align: center;
            animation: rise .8s cubic-bezier(.16,1,.3,1) both; }
        @keyframes rise { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: none; } }

        /* المشهد: تروس متعشّقة ومفتاح ربط — يوحي بالصيانة والإصلاح التقني */
        .scene { position: relative; width: 210px; height: 210px; margin: 0 auto 4px; }
        .float { position: absolute; inset: 0; animation: bob 5s ease-in-out infinite; }
        @keyframes bob { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }

        /* الترس: هيكل مسنّن ممتلئ بمحور داخلي */
        .gear { position: absolute; }
        .gear .body { fill: color-mix(in srgb, var(--accent) 16%, #fff);
            stroke: var(--accent); stroke-width: 1.3; stroke-linejoin: round; stroke-linecap: round; }
        .gear .hub  { fill: #fbfcfe; stroke: var(--accent); stroke-width: 1.3; }

        .gear-lg { width: 140px; height: 140px; top: 12px; left: 8px;
            filter: drop-shadow(0 14px 22px color-mix(in srgb, var(--accent) 34%, transparent));
            animation: spin 9s linear infinite; }
        .gear-sm { width: 96px; height: 96px; right: 8px; bottom: 12px;
            filter: drop-shadow(0 10px 16px color-mix(in srgb, var(--accent) 28%, transparent));
            animation: spin-rev 6s linear infinite; }
        /* الترس الأصغر بلون أعمق قليلاً لإحساس بالعمق */
        .gear-sm .body { fill: color-mix(in srgb, var(--accent) 24%, #fff);
            stroke: color-mix(in srgb, var(--accent) 80%, #6366f1); }
        .gear-sm .hub  { stroke: color-mix(in srgb, var(--accent) 80%, #6366f1); }
        @keyframes spin     { to { transform: rotate(360deg); } }
        @keyframes spin-rev { to { transform: rotate(-360deg); } }

        /* مفتاح الربط يتأرجح فوق التروس كأنه يعمل عليها */
        .wrench { position: absolute; top: -2px; left: 12px; width: 62px; height: 62px;
            color: var(--accent); transform-origin: 82% 82%;
            filter: drop-shadow(0 6px 10px color-mix(in srgb, var(--accent) 30%, transparent));
            animation: wrench-work 3.2s ease-in-out infinite; }
        .wrench svg { width: 100%; height: 100%; }
        @keyframes wrench-work { 0%,100% { transform: rotate(-26deg); } 45%,55% { transform: rotate(-6deg); } }

        /* رقم الخطأ */
        .code {
            font-size: 104px; font-weight: 900; line-height: 1; letter-spacing: 3px; margin-top: 8px;
            background: linear-gradient(120deg, var(--accent) 0%, var(--ink) 55%, var(--accent) 100%);
            background-size: 220% auto; -webkit-background-clip: text; background-clip: text; color: transparent;
            animation: shimmer 5s linear infinite;
        }
        @keyframes shimmer { to { background-position: 220% center; } }

        h1 { font-size: 24px; font-weight: 800; margin-top: 4px; }
        p { color: var(--muted); font-size: 15px; margin-top: 12px; line-height: 1.8; max-width: 440px; margin-inline: auto; }

        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 28px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
            padding: 13px 26px; border-radius: 14px; font-weight: 800; font-size: 14px; cursor: pointer; border: 0;
            transition: transform .18s ease, background .18s ease, filter .18s ease;
        }
        .btn svg { width: 17px; height: 17px; }
        .btn-primary { color: #fff; background: var(--accent); }
        .btn-primary:hover { filter: brightness(1.06); }
        .btn-ghost { background: transparent; color: #475569; border: 1.5px solid #e2e8f0; }
        .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }
        .btn:hover { transform: translateY(-2px); }
        .btn:active { transform: translateY(0); }

        .brand { margin-top: 26px; font-size: 12px; font-weight: 700; color: #94a3b8; }
        .brand b { color: var(--accent); }

        @media (max-width: 480px) {
            .code { font-size: 80px; }
            .scene { width: 180px; height: 180px; }
            .gear-lg { width: 120px; height: 120px; top: 8px; left: 6px; }
            .gear-sm { width: 84px; height: 84px; }
            .wrench { width: 54px; height: 54px; }
        }

        /* احترام تفضيل تقليل الحركة */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="stars" aria-hidden="true"></div>

    <div class="wrap">
        <div class="scene" aria-hidden="true">
            <div class="float">
                <svg class="gear gear-lg" viewBox="0 0 24 24">
                    <path class="body" d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                    <circle class="hub" cx="12" cy="12" r="3" />
                </svg>
                <svg class="gear gear-sm" viewBox="0 0 24 24">
                    <path class="body" d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                    <circle class="hub" cx="12" cy="12" r="3" />
                </svg>
                <span class="wrench">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
                    </svg>
                </span>
            </div>
        </div>

        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>

        <div class="actions">
            <a href="/admin" class="btn btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9"/><path d="M5 10v10h14V10"/></svg>
                العودة للوحة التحكم
            </a>
            <a href="/login" onclick="goBackFresh(event)" class="btn btn-ghost">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                الرجوع للخلف
            </a>
        </div>

        <div class="brand">برمجة وتصميم <b>كواكب التقنية</b> 🪐</div>
    </div>

    <script>
        // توليد نجوم لامعة خفيفة في الخلفية
        (function () {
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            var box = document.querySelector('.stars');
            if (!box) return;
            for (var i = 0; i < 26; i++) {
                var s = document.createElement('span');
                s.className = 'star';
                var size = 2 + Math.random() * 3;
                s.style.width = s.style.height = size + 'px';
                s.style.top = Math.random() * 100 + '%';
                s.style.left = Math.random() * 100 + '%';
                s.style.animationDuration = (2 + Math.random() * 3) + 's';
                s.style.animationDelay = (-Math.random() * 4) + 's';
                box.appendChild(s);
            }
        })();

        // الرجوع للخلف بتحميل جديد كامل (وليس من الذاكرة المؤقتة) حتى تُنشأ جلسة ورمز CSRF جديدان
        // فيتجاوز المستخدم خطأ 419 (انتهاء الجلسة) ويستطيع الدخول من جديد
        function goBackFresh(e) {
            e.preventDefault();
            var prev = document.referrer;
            // نتجنّب العودة لنفس الصفحة (التي سبّبت الخطأ) لئلا يتكرّر 419
            var target = (prev && prev !== window.location.href) ? prev : '/login';
            window.location.replace(target);
        }
    </script>
</body>
</html>
