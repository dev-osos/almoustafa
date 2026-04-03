<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1a1207">
    <title>تثبيت تطبيق المصطفى</title>
    <link rel="manifest" href="/manifest.php">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="assets/icons/icon-192x192.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&family=Noto+Kufi+Arabic:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --honey-50: #fefcf3;
            --honey-100: #fdf6d8;
            --honey-200: #faeaab;
            --honey-300: #f5d76e;
            --honey-400: #f1c40f;
            --honey-500: #d4a90a;
            --honey-600: #b08908;
            --honey-700: #8a6606;
            --honey-800: #5c4404;
            --honey-900: #1a1207;
            --amber-glow: rgba(241, 196, 15, 0.15);
            --amber-glow-strong: rgba(241, 196, 15, 0.35);
            --surface: #0f0b04;
            --surface-elevated: #1a1409;
            --surface-card: #221b0e;
            --text-primary: #fdf6d8;
            --text-secondary: #c9b87a;
            --text-muted: #8a7a4e;
            --radius-lg: 20px;
            --radius-xl: 28px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--surface);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* === HONEYCOMB BACKGROUND TEXTURE === */
        .bg-texture {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: 0.03;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='56' height='100'%3E%3Cpath d='M28 66L0 50L0 16L28 0L56 16L56 50L28 66L28 100' fill='none' stroke='%23f1c40f' stroke-width='1'/%3E%3Cpath d='M28 0L28 34L0 50L0 84L28 100L56 84L56 50L28 34' fill='none' stroke='%23f1c40f' stroke-width='1'/%3E%3C/svg%3E");
            background-size: 56px 100px;
        }

        /* === AMBIENT GLOW === */
        .ambient-glow {
            position: fixed;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            filter: blur(120px);
            pointer-events: none;
            z-index: 0;
        }
        .ambient-glow--top {
            top: -200px;
            left: 50%;
            transform: translateX(-50%);
            background: radial-gradient(circle, rgba(241,196,15,0.12) 0%, transparent 70%);
        }
        .ambient-glow--bottom {
            bottom: -300px;
            right: -100px;
            background: radial-gradient(circle, rgba(176,137,8,0.08) 0%, transparent 70%);
        }

        /* === LAYOUT === */
        .page-wrapper {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            width: 100%;
            max-width: 520px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* === HEADER === */
        .header {
            padding: 40px 0 20px;
            text-align: center;
        }

        .app-icon {
            width: 88px;
            height: 88px;
            border-radius: 22px;
            overflow: hidden;
            margin: 0 auto 20px;
            box-shadow:
                0 0 0 1px rgba(241,196,15,0.15),
                0 8px 32px rgba(0,0,0,0.5),
                0 0 60px rgba(241,196,15,0.1);
            animation: iconFloat 3s ease-in-out infinite;
            position: relative;
        }
        .app-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .app-icon::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 50%);
        }

        @keyframes iconFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }

        .app-name {
            font-family: 'Noto Kufi Arabic', 'Tajawal', sans-serif;
            font-size: 1.75rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--honey-300), var(--honey-400), var(--honey-500));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.02em;
            line-height: 1.3;
        }

        .app-subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 6px;
            font-weight: 300;
        }

        /* === DEVICE BADGE === */
        .device-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--surface-card);
            border: 1px solid rgba(241,196,15,0.1);
            border-radius: 100px;
            padding: 8px 18px;
            font-size: 0.82rem;
            color: var(--text-secondary);
            margin: 16px auto 0;
            animation: fadeSlideUp 0.6s ease-out 0.3s both;
        }
        .device-badge i {
            font-size: 1rem;
            color: var(--honey-400);
        }

        /* === INSTALL BUTTON (HERO) === */
        .install-hero {
            padding: 28px 0;
            text-align: center;
            animation: fadeSlideUp 0.6s ease-out 0.4s both;
        }

        .install-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            max-width: 320px;
            padding: 16px 32px;
            border: none;
            border-radius: 16px;
            font-family: 'Tajawal', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            background: linear-gradient(135deg, var(--honey-400), var(--honey-500));
            color: var(--honey-900);
            box-shadow:
                0 4px 20px rgba(241,196,15,0.3),
                0 0 60px rgba(241,196,15,0.1),
                inset 0 1px 0 rgba(255,255,255,0.2);
        }
        .install-btn::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 30%, rgba(255,255,255,0.2) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }
        .install-btn:hover::before {
            transform: translateX(100%);
        }
        .install-btn:hover {
            transform: translateY(-2px);
            box-shadow:
                0 8px 32px rgba(241,196,15,0.4),
                0 0 80px rgba(241,196,15,0.15),
                inset 0 1px 0 rgba(255,255,255,0.3);
        }
        .install-btn:active {
            transform: translateY(0) scale(0.98);
        }
        .install-btn i {
            font-size: 1.3rem;
        }

        .install-btn--secondary {
            background: var(--surface-card);
            border: 1px solid rgba(241,196,15,0.2);
            color: var(--honey-300);
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
        }
        .install-btn--secondary:hover {
            background: var(--surface-elevated);
            border-color: rgba(241,196,15,0.4);
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
        }

        .install-hint {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 12px;
        }

        /* === STEPS SECTION === */
        .section-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.78rem;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 20px;
        }
        .section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(to left, transparent, rgba(241,196,15,0.15));
        }

        .steps-container {
            padding: 0 0 32px;
        }

        .step-card {
            background: var(--surface-card);
            border: 1px solid rgba(241,196,15,0.06);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 14px;
            display: flex;
            gap: 16px;
            align-items: flex-start;
            position: relative;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(16px);
        }
        .step-card.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .step-card:hover {
            border-color: rgba(241,196,15,0.15);
            background: rgba(34,27,14,0.9);
        }

        .step-number {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.9rem;
            background: linear-gradient(135deg, rgba(241,196,15,0.15), rgba(241,196,15,0.05));
            color: var(--honey-400);
            border: 1px solid rgba(241,196,15,0.1);
        }

        .step-content {
            flex: 1;
        }
        .step-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .step-desc {
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .step-visual {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 12px;
        }

        /* === ICON ILLUSTRATIONS === */
        .icon-illustration {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            background: rgba(241,196,15,0.08);
            color: var(--honey-400);
            border: 1px solid rgba(241,196,15,0.1);
        }

        /* Menu dots illustration */
        .menu-dots {
            display: flex;
            flex-direction: column;
            gap: 3px;
            padding: 10px;
            background: rgba(241,196,15,0.08);
            border-radius: 10px;
            border: 1px solid rgba(241,196,15,0.1);
        }
        .menu-dots span {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--honey-400);
        }

        /* Share button illustration (iOS) */
        .share-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,122,255,0.12);
            border: 1px solid rgba(0,122,255,0.2);
            color: #007AFF;
            font-size: 1.3rem;
        }

        /* Keyboard shortcut visual */
        .kbd-combo {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            direction: ltr;
        }
        .kbd {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            padding: 0 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            font-family: -apple-system, BlinkMacSystemFont, monospace;
            background: var(--surface-elevated);
            border: 1px solid rgba(241,196,15,0.15);
            color: var(--honey-300);
        }
        .kbd-plus {
            color: var(--text-muted);
            font-size: 0.7rem;
        }

        /* === INSTALLED STATE === */
        .installed-state {
            text-align: center;
            padding: 48px 0;
            animation: fadeSlideUp 0.6s ease-out;
        }

        .installed-check {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(46,204,113,0.15), rgba(46,204,113,0.05));
            border: 2px solid rgba(46,204,113,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: #2ecc71;
            animation: checkPulse 2s ease-in-out infinite;
        }
        @keyframes checkPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(46,204,113,0.2); }
            50% { box-shadow: 0 0 0 12px rgba(46,204,113,0); }
        }

        .installed-title {
            font-family: 'Noto Kufi Arabic', 'Tajawal', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: #2ecc71;
            margin-bottom: 8px;
        }
        .installed-desc {
            font-size: 0.9rem;
            color: var(--text-secondary);
            line-height: 1.7;
        }

        /* === OTHER PLATFORMS === */
        .other-platforms {
            padding: 0 0 32px;
        }

        .platform-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: var(--surface-card);
            border: 1px solid rgba(241,196,15,0.06);
            border-radius: var(--radius-lg);
            color: var(--text-secondary);
            font-family: 'Tajawal', sans-serif;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .platform-toggle:hover {
            border-color: rgba(241,196,15,0.15);
        }
        .platform-toggle i {
            transition: transform 0.3s ease;
        }
        .platform-toggle.active i {
            transform: rotate(180deg);
        }

        .platforms-list {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
        }
        .platforms-list.open {
            max-height: 1200px;
        }

        .platform-item {
            background: var(--surface-card);
            border: 1px solid rgba(241,196,15,0.04);
            border-radius: 16px;
            padding: 16px 18px;
            margin-top: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .platform-item:hover {
            border-color: rgba(241,196,15,0.12);
        }
        .platform-item-header {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .platform-item-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .platform-item-icon--ios { background: rgba(0,122,255,0.1); color: #007AFF; }
        .platform-item-icon--android { background: rgba(61,220,132,0.1); color: #3DDC84; }
        .platform-item-icon--windows { background: rgba(0,120,215,0.1); color: #0078D7; }
        .platform-item-icon--mac { background: rgba(162,132,194,0.1); color: #A284C2; }
        .platform-item-icon--linux { background: rgba(255,165,0,0.1); color: #FFA500; }
        .platform-item-icon--chrome { background: rgba(66,133,244,0.1); color: #4285F4; }
        .platform-item-icon--edge { background: rgba(0,120,215,0.1); color: #0078D7; }
        .platform-item-icon--firefox { background: rgba(255,149,0,0.1); color: #FF9500; }
        .platform-item-icon--samsung { background: rgba(104,59,183,0.1); color: #683BB7; }

        .platform-item-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .platform-item-tag {
            margin-right: auto;
            margin-left: 0;
            font-size: 0.7rem;
            padding: 2px 10px;
            border-radius: 100px;
            font-weight: 500;
        }
        .platform-item-tag--detected {
            background: rgba(241,196,15,0.12);
            color: var(--honey-400);
        }
        .platform-item-arrow {
            color: var(--text-muted);
            font-size: 0.8rem;
            transition: transform 0.3s ease;
        }
        .platform-item.expanded .platform-item-arrow {
            transform: rotate(90deg);
        }

        .platform-item-steps {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease;
        }
        .platform-item.expanded .platform-item-steps {
            max-height: 500px;
        }
        .platform-step {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 10px 0;
            border-bottom: 1px solid rgba(241,196,15,0.04);
        }
        .platform-step:first-child {
            padding-top: 16px;
        }
        .platform-step:last-child {
            border-bottom: none;
            padding-bottom: 4px;
        }
        .platform-step-num {
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            background: rgba(241,196,15,0.08);
            color: var(--honey-400);
        }
        .platform-step-text {
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }
        .platform-step-text strong {
            color: var(--text-primary);
            font-weight: 600;
        }

        .platform-note {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            margin-top: 10px;
            padding: 10px 14px;
            background: rgba(241,196,15,0.04);
            border-radius: 10px;
            border: 1px solid rgba(241,196,15,0.06);
        }
        .platform-note i {
            color: var(--honey-400);
            font-size: 0.85rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .platform-note span {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* === FEATURES SECTION === */
        .features {
            padding: 8px 0 40px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .feature-card {
            background: var(--surface-card);
            border: 1px solid rgba(241,196,15,0.04);
            border-radius: 16px;
            padding: 16px;
            text-align: center;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(12px);
        }
        .feature-card.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .feature-card:hover {
            border-color: rgba(241,196,15,0.12);
        }
        .feature-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 1.15rem;
            background: rgba(241,196,15,0.08);
            color: var(--honey-400);
        }
        .feature-title {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .feature-desc {
            font-size: 0.72rem;
            color: var(--text-muted);
        }

        /* === FOOTER === */
        .footer {
            text-align: center;
            padding: 20px 0 40px;
            border-top: 1px solid rgba(241,196,15,0.06);
        }
        .footer-text {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .footer-link {
            color: var(--honey-500);
            text-decoration: none;
        }
        .footer-link:hover {
            color: var(--honey-400);
        }

        /* === ANIMATIONS === */
        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-in {
            animation: fadeSlideUp 0.5s ease-out both;
        }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }

        /* === BROWSER MOCKUP === */
        .browser-hint {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: rgba(241,196,15,0.05);
            border: 1px solid rgba(241,196,15,0.08);
            border-radius: 14px;
            margin-top: 16px;
        }
        .browser-hint-icon {
            font-size: 1.3rem;
            color: var(--honey-400);
        }
        .browser-hint-text {
            font-size: 0.82rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        /* === RESPONSIVE === */
        @media (max-width: 380px) {
            .app-name { font-size: 1.5rem; }
            .features-grid { grid-template-columns: 1fr; }
            .step-card { padding: 16px; }
        }

        /* === NO-SUPPORT BANNER === */
        .no-support-banner {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 14px 16px;
            background: rgba(231,76,60,0.08);
            border: 1px solid rgba(231,76,60,0.15);
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .no-support-banner i {
            color: #e74c3c;
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .no-support-banner .text {
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }
        .no-support-banner .text strong {
            color: #e74c3c;
        }

        /* === SCROLL INDICATOR === */
        .scroll-indicator {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            z-index: 100;
            background: transparent;
        }
        .scroll-indicator-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(to left, var(--honey-400), var(--honey-600));
            transition: width 0.1s linear;
        }

        /* hide when inside PWA */
        @media (display-mode: standalone) {
            .install-hero { display: none; }
        }
    </style>
</head>
<body>

<div class="bg-texture"></div>
<div class="ambient-glow ambient-glow--top"></div>
<div class="ambient-glow ambient-glow--bottom"></div>

<div class="scroll-indicator">
    <div class="scroll-indicator-bar" id="scrollBar"></div>
</div>

<div class="page-wrapper">
    <div class="container">

        <!-- HEADER -->
        <header class="header animate-in">
            <div class="app-icon">
                <img src="assets/icons/icon-192x192.png" alt="المصطفى">
            </div>
            <h1 class="app-name">تثبيت تطبيق المصطفى</h1>
            <p class="app-subtitle">نظام إدارة متكامل — يعمل بدون إنترنت</p>
            <div class="device-badge" id="deviceBadge">
                <i class="bi bi-phone"></i>
                <span id="deviceName">جاري الكشف عن جهازك...</span>
            </div>
        </header>

        <!-- INSTALLED STATE (hidden by default) -->
        <div class="installed-state" id="installedState" style="display:none">
            <div class="installed-check">
                <i class="bi bi-check-lg"></i>
            </div>
            <h2 class="installed-title">التطبيق مثبّت بالفعل!</h2>
            <p class="installed-desc">
                تطبيق المصطفى مثبّت على جهازك.<br>
                يمكنك فتحه من الشاشة الرئيسية.
            </p>
            <a href="/" class="install-btn" style="margin-top:24px; text-decoration:none; max-width:240px;">
                <i class="bi bi-box-arrow-up-left"></i>
                فتح التطبيق
            </a>
        </div>

        <!-- MAIN CONTENT (hidden when installed) -->
        <div id="mainContent">

            <!-- INSTALL BUTTON -->
            <div class="install-hero" id="installHero">
                <button class="install-btn" id="installBtn" type="button">
                    <i class="bi bi-download"></i>
                    <span id="installBtnText">تثبيت التطبيق</span>
                </button>
                <p class="install-hint" id="installHint">مجاني — لا يحتاج متجر تطبيقات</p>
            </div>

            <!-- NO-SUPPORT BANNER (conditionally shown) -->
            <div id="noSupportBanner" class="no-support-banner" style="display:none">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div class="text">
                    <strong>متصفحك لا يدعم التثبيت المباشر.</strong><br>
                    <span id="noSupportText">يُنصح باستخدام Google Chrome لتجربة تثبيت أفضل.</span>
                </div>
            </div>

            <!-- STEPS -->
            <div class="steps-container" id="stepsContainer">
                <div class="section-label animate-in delay-3">
                    <span id="stepsLabel">خطوات التثبيت</span>
                </div>
                <div id="stepsContent">
                    <!-- Steps injected by JS -->
                </div>
            </div>

            <!-- BROWSER HINT -->
            <div class="browser-hint animate-in delay-5" id="browserHint" style="display:none">
                <i class="bi bi-lightbulb-fill browser-hint-icon"></i>
                <span class="browser-hint-text" id="browserHintText"></span>
            </div>

            <!-- OTHER PLATFORMS -->
            <div class="other-platforms">
                <button class="platform-toggle" id="platformToggle" type="button">
                    <span><i class="bi bi-device-hdd me-2"></i>تعليمات أجهزة أخرى</span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="platforms-list" id="platformsList">
                    <!-- Injected by JS -->
                </div>
            </div>

            <!-- FEATURES -->
            <div class="features">
                <div class="section-label animate-in">
                    <span>مميزات التطبيق</span>
                </div>
                <div class="features-grid">
                    <div class="feature-card" data-observe>
                        <div class="feature-icon"><i class="bi bi-wifi-off"></i></div>
                        <div class="feature-title">يعمل بدون إنترنت</div>
                        <div class="feature-desc">تصفح البيانات بدون اتصال</div>
                    </div>
                    <div class="feature-card" data-observe>
                        <div class="feature-icon"><i class="bi bi-lightning-charge"></i></div>
                        <div class="feature-title">سريع جداً</div>
                        <div class="feature-desc">يفتح فوراً مثل التطبيقات</div>
                    </div>
                    <div class="feature-card" data-observe>
                        <div class="feature-icon"><i class="bi bi-bell"></i></div>
                        <div class="feature-title">إشعارات فورية</div>
                        <div class="feature-desc">تنبيهات مهمة لا تفوتك</div>
                    </div>
                    <div class="feature-card" data-observe>
                        <div class="feature-icon"><i class="bi bi-phone"></i></div>
                        <div class="feature-title">شاشة كاملة</div>
                        <div class="feature-desc">بدون شريط المتصفح</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <footer class="footer">
            <p class="footer-text">المصطفى — AL MOUSTAFA © <?php echo date('Y'); ?></p>
        </footer>

    </div>
</div>

<script>
(function() {
    'use strict';

    // ========== DEVICE DETECTION ==========
    const ua = navigator.userAgent;
    const platform = navigator.platform || '';

    const detect = {
        iOS: /iPad|iPhone|iPod/.test(ua) && !window.MSStream,
        iPad: /iPad/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
        android: /android/i.test(ua),
        windows: /Win/.test(platform),
        mac: /Mac/.test(platform) && navigator.maxTouchPoints <= 1,
        linux: /Linux/.test(platform) && !/android/i.test(ua),
        chrome: /Chrome/.test(ua) && !/Edg|OPR|SamsungBrowser/.test(ua),
        edge: /Edg/.test(ua),
        firefox: /Firefox/.test(ua),
        safari: /Safari/.test(ua) && !/Chrome/.test(ua),
        samsung: /SamsungBrowser/.test(ua),
        opera: /OPR/.test(ua),
        get mobile() { return this.iOS || this.iPad || this.android; },
        get androidVersion() {
            const m = ua.match(/Android\s([0-9.]*)/);
            return m ? parseFloat(m[1]) : null;
        },
        get chromeVersion() {
            const m = ua.match(/Chrome\/(\d+)/);
            return m ? parseInt(m[1]) : null;
        }
    };

    // Determine primary platform key
    function getPlatformKey() {
        if (detect.iOS || detect.iPad) return 'ios';
        if (detect.android && detect.samsung) return 'android-samsung';
        if (detect.android && detect.firefox) return 'android-firefox';
        if (detect.android && detect.chrome) return 'android-chrome';
        if (detect.android) return 'android-chrome'; // default android
        if (detect.windows && detect.edge) return 'windows-edge';
        if (detect.windows && detect.chrome) return 'windows-chrome';
        if (detect.windows && detect.firefox) return 'windows-firefox';
        if (detect.mac && detect.safari) return 'mac-safari';
        if (detect.mac && detect.chrome) return 'mac-chrome';
        if (detect.mac && detect.firefox) return 'mac-firefox';
        if (detect.linux && detect.chrome) return 'linux-chrome';
        if (detect.linux && detect.firefox) return 'linux-firefox';
        if (detect.linux) return 'linux-chrome';
        return 'desktop-chrome'; // fallback
    }

    const currentPlatform = getPlatformKey();

    // ========== PLATFORM DATA ==========
    const platforms = {
        'ios': {
            name: 'iPhone / iPad — Safari',
            icon: 'bi-apple',
            iconClass: 'platform-item-icon--ios',
            deviceBadge: { icon: 'bi-apple', text: 'جهاز Apple — Safari' },
            supportsNativeInstall: false,
            noSupportText: null,
            steps: [
                {
                    title: 'افتح قائمة المشاركة',
                    desc: 'اضغط على أيقونة المشاركة <strong><i class="bi bi-box-arrow-up"></i></strong> في شريط الأدوات السفلي',
                    visual: 'share'
                },
                {
                    title: 'اختر "إضافة إلى الشاشة الرئيسية"',
                    desc: 'مرر لأسفل في القائمة وابحث عن <strong>"إضافة إلى الشاشة الرئيسية"</strong> أو <strong>"Add to Home Screen"</strong>',
                    visual: 'plus'
                },
                {
                    title: 'اضغط "إضافة"',
                    desc: 'أكّد الاسم ثم اضغط <strong>"إضافة"</strong> في الزاوية العلوية',
                    visual: 'check'
                }
            ],
            hint: 'يجب استخدام متصفح Safari — المتصفحات الأخرى على iOS لا تدعم التثبيت'
        },
        'android-chrome': {
            name: 'Android — Chrome',
            icon: 'bi-google',
            iconClass: 'platform-item-icon--chrome',
            deviceBadge: { icon: 'bi-phone', text: 'Android — Chrome' },
            supportsNativeInstall: true,
            steps: [
                {
                    title: 'اضغط "تثبيت التطبيق" أعلاه',
                    desc: 'أو افتح <strong>قائمة المتصفح</strong> <strong>⋮</strong> من الأعلى',
                    visual: 'download'
                },
                {
                    title: 'اختر "تثبيت التطبيق"',
                    desc: 'ابحث عن <strong>"تثبيت التطبيق"</strong> أو <strong>"Install app"</strong> أو <strong>"إضافة إلى الشاشة الرئيسية"</strong>',
                    visual: 'menu'
                },
                {
                    title: 'أكّد التثبيت',
                    desc: 'اضغط <strong>"تثبيت"</strong> في النافذة المنبثقة، وسيظهر التطبيق على شاشتك الرئيسية',
                    visual: 'check'
                }
            ]
        },
        'android-samsung': {
            name: 'Android — Samsung Internet',
            icon: 'bi-browser-chrome',
            iconClass: 'platform-item-icon--samsung',
            deviceBadge: { icon: 'bi-phone', text: 'Android — Samsung Internet' },
            supportsNativeInstall: false,
            steps: [
                {
                    title: 'افتح القائمة',
                    desc: 'اضغط على أيقونة <strong>☰</strong> (ثلاثة خطوط) في أسفل المتصفح',
                    visual: 'menu'
                },
                {
                    title: 'اختر "إضافة إلى الشاشة الرئيسية"',
                    desc: 'ابحث عن <strong>"إضافة الصفحة إلى"</strong> ثم <strong>"الشاشة الرئيسية"</strong>',
                    visual: 'plus'
                },
                {
                    title: 'أكّد الإضافة',
                    desc: 'اضغط <strong>"إضافة"</strong> وسيظهر التطبيق على شاشتك الرئيسية',
                    visual: 'check'
                }
            ],
            hint: 'للحصول على أفضل تجربة، يُنصح باستخدام Google Chrome'
        },
        'android-firefox': {
            name: 'Android — Firefox',
            icon: 'bi-browser-firefox',
            iconClass: 'platform-item-icon--firefox',
            deviceBadge: { icon: 'bi-phone', text: 'Android — Firefox' },
            supportsNativeInstall: false,
            steps: [
                {
                    title: 'افتح قائمة المتصفح',
                    desc: 'اضغط على <strong>⋮</strong> (ثلاث نقاط) في أعلى أو أسفل المتصفح',
                    visual: 'menu'
                },
                {
                    title: 'اختر "تثبيت"',
                    desc: 'ابحث عن خيار <strong>"تثبيت"</strong> أو <strong>"Install"</strong> في القائمة',
                    visual: 'download'
                },
                {
                    title: 'أكّد التثبيت',
                    desc: 'اضغط <strong>"إضافة"</strong> لإتمام التثبيت',
                    visual: 'check'
                }
            ]
        },
        'windows-chrome': {
            name: 'Windows — Chrome',
            icon: 'bi-google',
            iconClass: 'platform-item-icon--chrome',
            deviceBadge: { icon: 'bi-pc-display', text: 'Windows — Chrome' },
            supportsNativeInstall: true,
            steps: [
                {
                    title: 'اضغط "تثبيت التطبيق" أعلاه',
                    desc: 'أو اضغط على أيقونة التثبيت <strong><i class="bi bi-box-arrow-in-down"></i></strong> في <strong>شريط العناوين</strong>',
                    visual: 'download'
                },
                {
                    title: 'أو من قائمة Chrome',
                    desc: 'اضغط <strong>⋮</strong> ← <strong>"تثبيت المصطفى"</strong> أو <strong>"Install"</strong>',
                    visual: 'menu'
                },
                {
                    title: 'أكّد التثبيت',
                    desc: 'اضغط <strong>"تثبيت"</strong> — سيفتح التطبيق في نافذة مستقلة',
                    visual: 'check'
                }
            ]
        },
        'windows-edge': {
            name: 'Windows — Edge',
            icon: 'bi-browser-edge',
            iconClass: 'platform-item-icon--edge',
            deviceBadge: { icon: 'bi-pc-display', text: 'Windows — Edge' },
            supportsNativeInstall: true,
            steps: [
                {
                    title: 'اضغط "تثبيت التطبيق" أعلاه',
                    desc: 'أو اضغط على أيقونة التثبيت <strong><i class="bi bi-box-arrow-in-down"></i></strong> في <strong>شريط العناوين</strong>',
                    visual: 'download'
                },
                {
                    title: 'أو من قائمة Edge',
                    desc: 'اضغط <strong>⋯</strong> ← <strong>"تطبيقات"</strong> ← <strong>"تثبيت هذا الموقع كتطبيق"</strong>',
                    visual: 'menu'
                },
                {
                    title: 'أكّد التثبيت',
                    desc: 'اضغط <strong>"تثبيت"</strong> وسيظهر التطبيق في قائمة Start',
                    visual: 'check'
                }
            ]
        },
        'windows-firefox': {
            name: 'Windows — Firefox',
            icon: 'bi-browser-firefox',
            iconClass: 'platform-item-icon--firefox',
            deviceBadge: { icon: 'bi-pc-display', text: 'Windows — Firefox' },
            supportsNativeInstall: false,
            noSupportText: 'متصفح Firefox على سطح المكتب لا يدعم تثبيت التطبيقات. يُنصح باستخدام Chrome أو Edge.',
            steps: []
        },
        'mac-chrome': {
            name: 'macOS — Chrome',
            icon: 'bi-google',
            iconClass: 'platform-item-icon--chrome',
            deviceBadge: { icon: 'bi-laptop', text: 'macOS — Chrome' },
            supportsNativeInstall: true,
            steps: [
                {
                    title: 'اضغط "تثبيت التطبيق" أعلاه',
                    desc: 'أو اضغط على أيقونة التثبيت <strong><i class="bi bi-box-arrow-in-down"></i></strong> في <strong>شريط العناوين</strong>',
                    visual: 'download'
                },
                {
                    title: 'أو من قائمة Chrome',
                    desc: 'اضغط <strong>⋮</strong> ← <strong>"تثبيت المصطفى"</strong>',
                    visual: 'menu'
                },
                {
                    title: 'أكّد التثبيت',
                    desc: 'اضغط <strong>"تثبيت"</strong> — سيفتح التطبيق في نافذة مستقلة ويظهر في Dock',
                    visual: 'check'
                }
            ]
        },
        'mac-safari': {
            name: 'macOS — Safari',
            icon: 'bi-browser-safari',
            iconClass: 'platform-item-icon--mac',
            deviceBadge: { icon: 'bi-laptop', text: 'macOS — Safari' },
            supportsNativeInstall: false,
            noSupportText: 'Safari على macOS لا يدعم تثبيت التطبيقات بشكل كامل حالياً. يُنصح باستخدام Google Chrome.',
            steps: [
                {
                    title: 'افتح Google Chrome',
                    desc: 'حمّل <strong>Google Chrome</strong> إذا لم يكن مثبتاً، ثم افتح هذه الصفحة فيه',
                    visual: 'download'
                },
                {
                    title: 'ثبّت من Chrome',
                    desc: 'اتبع خطوات التثبيت عبر Chrome الموضحة في هذه الصفحة',
                    visual: 'check'
                }
            ],
            hint: 'macOS Sonoma (14+) يدعم إضافة مواقع إلى Dock من Safari عبر: ملف ← إضافة إلى Dock'
        },
        'mac-firefox': {
            name: 'macOS — Firefox',
            icon: 'bi-browser-firefox',
            iconClass: 'platform-item-icon--firefox',
            deviceBadge: { icon: 'bi-laptop', text: 'macOS — Firefox' },
            supportsNativeInstall: false,
            noSupportText: 'Firefox على macOS لا يدعم تثبيت التطبيقات. يُنصح باستخدام Google Chrome.',
            steps: []
        },
        'linux-chrome': {
            name: 'Linux — Chrome',
            icon: 'bi-google',
            iconClass: 'platform-item-icon--chrome',
            deviceBadge: { icon: 'bi-pc-display', text: 'Linux — Chrome' },
            supportsNativeInstall: true,
            steps: [
                {
                    title: 'اضغط "تثبيت التطبيق" أعلاه',
                    desc: 'أو اضغط على أيقونة التثبيت في شريط العناوين',
                    visual: 'download'
                },
                {
                    title: 'أو من قائمة Chrome',
                    desc: 'اضغط <strong>⋮</strong> ← <strong>"تثبيت المصطفى"</strong>',
                    visual: 'menu'
                },
                {
                    title: 'أكّد التثبيت',
                    desc: 'اضغط <strong>"تثبيت"</strong> — سيظهر التطبيق في قائمة التطبيقات',
                    visual: 'check'
                }
            ]
        },
        'linux-firefox': {
            name: 'Linux — Firefox',
            icon: 'bi-browser-firefox',
            iconClass: 'platform-item-icon--firefox',
            deviceBadge: { icon: 'bi-pc-display', text: 'Linux — Firefox' },
            supportsNativeInstall: false,
            noSupportText: 'Firefox على Linux لا يدعم تثبيت التطبيقات. يُنصح باستخدام Google Chrome.',
            steps: []
        }
    };

    // Fallback
    if (!platforms[currentPlatform]) {
        platforms[currentPlatform] = platforms['windows-chrome'];
    }

    const currentData = platforms[currentPlatform];

    // ========== CHECK INSTALLED STATE ==========
    function isInstalled() {
        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
        if (window.navigator.standalone === true) return true;
        if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) return true;
        try { if (sessionStorage.getItem('pwa_installed') === 'true') return true; } catch(e) {}
        return false;
    }

    if (isInstalled()) {
        document.getElementById('installedState').style.display = '';
        document.getElementById('mainContent').style.display = 'none';
    }

    // ========== POPULATE DEVICE BADGE ==========
    const badge = document.getElementById('deviceBadge');
    const badgeIcon = badge.querySelector('i');
    const deviceNameEl = document.getElementById('deviceName');
    badgeIcon.className = 'bi ' + currentData.deviceBadge.icon;
    deviceNameEl.textContent = currentData.deviceBadge.text;

    // ========== POPULATE STEPS ==========
    const stepsContent = document.getElementById('stepsContent');

    function getVisualIcon(type) {
        const map = {
            'share': '<div class="share-icon-box"><i class="bi bi-box-arrow-up"></i></div>',
            'menu': '<div class="menu-dots"><span></span><span></span><span></span></div>',
            'download': '<div class="icon-illustration"><i class="bi bi-download"></i></div>',
            'plus': '<div class="icon-illustration"><i class="bi bi-plus-square"></i></div>',
            'check': '<div class="icon-illustration" style="background:rgba(46,204,113,0.1);color:#2ecc71;border-color:rgba(46,204,113,0.15)"><i class="bi bi-check-lg"></i></div>'
        };
        return map[type] || '';
    }

    if (currentData.steps && currentData.steps.length > 0) {
        currentData.steps.forEach((step, i) => {
            const card = document.createElement('div');
            card.className = 'step-card';
            card.innerHTML = `
                <div class="step-number">${i + 1}</div>
                <div class="step-content">
                    <div class="step-title">${step.title}</div>
                    <div class="step-desc">${step.desc}</div>
                </div>
                ${step.visual ? getVisualIcon(step.visual) : ''}
            `;
            stepsContent.appendChild(card);

            // Stagger animation
            setTimeout(() => card.classList.add('visible'), 300 + (i * 120));
        });
    } else {
        document.getElementById('stepsContainer').style.display = 'none';
    }

    // ========== NO-SUPPORT BANNER ==========
    if (currentData.noSupportText) {
        const banner = document.getElementById('noSupportBanner');
        banner.style.display = '';
        document.getElementById('noSupportText').textContent = currentData.noSupportText;
    }

    // ========== BROWSER HINT ==========
    if (currentData.hint) {
        const hintEl = document.getElementById('browserHint');
        hintEl.style.display = '';
        document.getElementById('browserHintText').textContent = currentData.hint;
    }

    // ========== INSTALL BUTTON BEHAVIOR ==========
    let deferredPrompt = null;
    const installBtn = document.getElementById('installBtn');
    const installBtnText = document.getElementById('installBtnText');
    const installHint = document.getElementById('installHint');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        installBtnText.textContent = 'تثبيت التطبيق الآن';
        installHint.textContent = 'جاهز للتثبيت — اضغط الزر';
    });

    installBtn.addEventListener('click', async () => {
        // If native prompt available
        if (deferredPrompt) {
            try {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    showInstalledState();
                }
                deferredPrompt = null;
            } catch (err) {
                console.error('Install prompt error:', err);
            }
            return;
        }

        // iOS — scroll to steps
        if (detect.iOS || detect.iPad) {
            document.getElementById('stepsContainer').scrollIntoView({ behavior: 'smooth' });
            // Pulse the steps
            document.querySelectorAll('.step-card').forEach((card, i) => {
                setTimeout(() => {
                    card.style.borderColor = 'rgba(241,196,15,0.3)';
                    setTimeout(() => card.style.borderColor = '', 600);
                }, i * 150);
            });
            return;
        }

        // Android without native prompt — scroll to steps
        if (detect.android) {
            document.getElementById('stepsContainer').scrollIntoView({ behavior: 'smooth' });
            return;
        }

        // Desktop — scroll to steps
        document.getElementById('stepsContainer').scrollIntoView({ behavior: 'smooth' });
    });

    // iOS-specific button text
    if (detect.iOS || detect.iPad) {
        installBtnText.textContent = 'كيفية التثبيت';
        installBtn.querySelector('i').className = 'bi bi-info-circle';
        installHint.textContent = 'اتبع الخطوات أدناه لتثبيت التطبيق';
    }

    // No-support platforms — change button to "open in Chrome"
    if (currentData.noSupportText && currentData.steps.length === 0) {
        installBtnText.textContent = 'افتح في Chrome للتثبيت';
        installBtn.querySelector('i').className = 'bi bi-google';
        installBtn.classList.add('install-btn--secondary');
        installHint.textContent = 'متصفحك الحالي لا يدعم التثبيت';
        installBtn.addEventListener('click', () => {
            // Can't programmatically open in Chrome, just scroll to hint
        });
    }

    window.addEventListener('appinstalled', () => {
        showInstalledState();
        try { sessionStorage.setItem('pwa_installed', 'true'); } catch(e) {}
    });

    function showInstalledState() {
        document.getElementById('installedState').style.display = '';
        document.getElementById('mainContent').style.display = 'none';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ========== OTHER PLATFORMS LIST ==========
    const platformsList = document.getElementById('platformsList');
    const platformToggle = document.getElementById('platformToggle');

    // Build list of other platforms (excluding current)
    const otherPlatformKeys = Object.keys(platforms).filter(k => k !== currentPlatform);

    // Group by OS type for better organization
    const groups = {
        'mobile': { label: 'الهواتف', keys: [] },
        'desktop': { label: 'أجهزة الكمبيوتر', keys: [] }
    };

    otherPlatformKeys.forEach(k => {
        if (k.startsWith('ios') || k.startsWith('android')) {
            groups.mobile.keys.push(k);
        } else {
            groups.desktop.keys.push(k);
        }
    });

    Object.values(groups).forEach(group => {
        group.keys.forEach(key => {
            const p = platforms[key];
            if (!p) return;

            const item = document.createElement('div');
            item.className = 'platform-item';

            let stepsHTML = '';
            if (p.steps && p.steps.length > 0) {
                stepsHTML = '<div class="platform-item-steps">';
                p.steps.forEach((s, i) => {
                    stepsHTML += `<div class="platform-step">
                        <div class="platform-step-num">${i+1}</div>
                        <div class="platform-step-text">${s.desc}</div>
                    </div>`;
                });
                if (p.hint) {
                    stepsHTML += `<div class="platform-note"><i class="bi bi-info-circle"></i><span>${p.hint}</span></div>`;
                }
                if (p.noSupportText) {
                    stepsHTML += `<div class="platform-note"><i class="bi bi-exclamation-triangle"></i><span>${p.noSupportText}</span></div>`;
                }
                stepsHTML += '</div>';
            } else if (p.noSupportText) {
                stepsHTML = `<div class="platform-item-steps"><div class="platform-note" style="margin-top:14px"><i class="bi bi-exclamation-triangle"></i><span>${p.noSupportText}</span></div></div>`;
            }

            item.innerHTML = `
                <div class="platform-item-header">
                    <div class="platform-item-icon ${p.iconClass}"><i class="bi ${p.icon}"></i></div>
                    <div class="platform-item-name">${p.name}</div>
                    ${(p.steps && p.steps.length > 0) || p.noSupportText ? '<i class="bi bi-chevron-left platform-item-arrow"></i>' : ''}
                </div>
                ${stepsHTML}
            `;

            if ((p.steps && p.steps.length > 0) || p.noSupportText) {
                item.addEventListener('click', () => {
                    const wasExpanded = item.classList.contains('expanded');
                    // Close all others
                    platformsList.querySelectorAll('.platform-item.expanded').forEach(el => el.classList.remove('expanded'));
                    if (!wasExpanded) item.classList.add('expanded');
                });
            }

            platformsList.appendChild(item);
        });
    });

    platformToggle.addEventListener('click', () => {
        platformToggle.classList.toggle('active');
        platformsList.classList.toggle('open');
    });

    // ========== SCROLL PROGRESS ==========
    window.addEventListener('scroll', () => {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const progress = docHeight > 0 ? (scrollTop / docHeight) * 100 : 0;
        document.getElementById('scrollBar').style.width = progress + '%';
    }, { passive: true });

    // ========== INTERSECTION OBSERVER ==========
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    document.querySelectorAll('.feature-card[data-observe]').forEach(el => observer.observe(el));

    // ========== SERVICE WORKER REGISTRATION ==========
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    }

})();
</script>

</body>
</html>
