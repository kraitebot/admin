<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kraite Prototype</title>

    @include('hub-ui::partials.scripts')
    @include('hub-ui::partials.styles')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1f2937; background: #fffdf9; }
        .prototype { min-height: 100vh; position: relative; overflow: hidden; background: #fffdf9; }
        .warm-edge { position: absolute; inset: 0 auto 0 0; width: 380px; background: linear-gradient(180deg, #ffc94f 0%, #f2a14c 48%, #8f3518 100%); }
        .top-line { position: absolute; left: 0; right: 0; top: 0; height: 7px; background: linear-gradient(90deg, #fac657 0%, #f45da1 47%, #8ddac1 100%); z-index: 3; }
        .soft-panel { position: absolute; left: 145px; top: 32px; bottom: 28px; width: 560px; border-radius: 56px; background: rgba(248, 239, 236, 0.86); border: 1px solid rgba(255,255,255,0.78); box-shadow: 0 28px 90px rgba(88, 52, 27, 0.18); transform: rotate(-2deg); transform-origin: top left; backdrop-filter: blur(18px); }
        .shell { position: relative; z-index: 5; min-height: 100vh; display: flex; }
        .sidebar { width: 430px; flex: 0 0 430px; padding: 56px 44px 40px 72px; }
        .side-inner { max-width: 325px; }
        .user-row { display: flex; align-items: center; justify-content: space-between; gap: 22px; }
        .user-meta { display: flex; align-items: center; gap: 18px; }
        .avatar { width: 76px; height: 76px; border-radius: 999px; background: #0f172a; display: flex; align-items: center; justify-content: center; box-shadow: 0 14px 36px rgba(15, 23, 42, 0.14); }
        .avatar img { width: 54px; height: 54px; }
        .welcome { color: #6b7280; font-size: 20px; font-weight: 500; line-height: 1.1; }
        .name { color: #273244; font-size: 32px; font-weight: 800; line-height: 1.1; margin-top: 6px; }
        .icon-button { width: 66px; height: 66px; border-radius: 18px; border: 1px solid #e5e7eb; background: white; color: #64748b; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08); }
        .icon-button svg { width: 30px; height: 30px; }
        .search { margin-top: 60px; height: 58px; display: flex; align-items: center; gap: 24px; color: #6b7280; padding-left: 14px; font-size: 26px; font-weight: 500; }
        .search svg { width: 30px; height: 30px; color: #109962; }
        .nav { margin-top: 36px; display: flex; flex-direction: column; gap: 15px; }
        .nav-item { height: 66px; border-radius: 24px; display: flex; align-items: center; justify-content: space-between; padding: 0 18px; color: #6b7280; text-decoration: none; }
        .nav-left { display: flex; align-items: center; gap: 24px; font-size: 22px; font-weight: 500; }
        .nav-left svg, .nav-menu { width: 28px; height: 28px; }
        .nav-menu { color: #c5cbd3; }
        .nav-active { background: white; color: #1f2937; box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08); }
        .nav-active .nav-left { font-weight: 800; }
        .pill { border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 900; letter-spacing: .04em; }
        .pill-green { background: #109962; color: white; }
        .pill-red { background: #fee2e2; color: #dc2626; }
        .content { flex: 1; min-width: 0; padding: 48px 52px 56px 56px; }
        .content-inner { max-width: 1360px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; gap: 28px; padding-bottom: 34px; border-bottom: 1px solid #e5e7eb; }
        .header-left { display: flex; align-items: center; gap: 26px; }
        .back { width: 66px; height: 66px; border: 0; border-radius: 24px; background: #f3f4f6; color: #64748b; display: flex; align-items: center; justify-content: center; }
        .back svg { width: 30px; height: 30px; }
        .eyebrow { color: #109962; font-size: 15px; font-weight: 900; letter-spacing: .14em; text-transform: uppercase; }
        h1 { margin: 4px 0 0; color: #2d3748; font-size: 50px; line-height: 1.06; font-weight: 900; letter-spacing: -.01em; }
        .main-grid { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 42px; padding-top: 42px; }
        h2 { margin: 0; color: #2d3748; font-size: 36px; line-height: 1.15; font-weight: 850; }
        .lead { margin: 16px 0 0; max-width: 760px; color: #6b7280; font-size: 24px; line-height: 1.45; font-weight: 400; }
        .field-group { margin-top: 36px; max-width: 840px; }
        .label { display: block; margin-bottom: 14px; color: #273244; font-size: 24px; font-weight: 800; }
        .input-like { position: relative; height: 76px; border: 1px solid #e5e7eb; border-radius: 24px; background: white; color: #374151; display: flex; align-items: center; padding: 0 68px 0 26px; font-size: 24px; font-weight: 600; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.03); }
        .input-icon { position: absolute; right: 18px; top: 50%; width: 44px; height: 44px; transform: translateY(-50%); border-radius: 999px; background: #e8fbf2; color: #109962; display: flex; align-items: center; justify-content: center; }
        .input-icon svg { width: 24px; height: 24px; }
        .choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .choice { min-height: 132px; border: 1px solid #e5e7eb; border-radius: 24px; background: white; padding: 27px 30px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .choice-danger { border: 2px solid #f87171; background: #fff4f4; box-shadow: 0 0 0 5px rgba(248, 113, 113, 0.10); }
        .choice-kicker { color: #9ca3af; font-size: 14px; font-weight: 900; text-transform: uppercase; letter-spacing: .14em; }
        .choice-danger .choice-kicker { color: #dc2626; }
        .choice-title { margin-top: 10px; color: #1f2937; font-size: 27px; line-height: 1.15; font-weight: 850; }
        .choice-muted .choice-title { color: #6b7280; font-weight: 650; }
        .choice-action { width: 52px; height: 52px; flex: 0 0 52px; border-radius: 999px; border: 0; background: white; color: #111827; display: flex; align-items: center; justify-content: center; }
        .choice-action svg { width: 26px; height: 26px; }
        .radio { width: 50px; height: 50px; flex: 0 0 50px; border-radius: 999px; border: 2px solid #e5e7eb; background: white; }
        .notice { margin-top: 42px; max-width: 840px; border: 1px solid #fecaca; border-radius: 24px; background: #fff4f4; color: #8b2d2d; padding: 24px 28px; display: flex; gap: 18px; align-items: flex-start; font-size: 20px; line-height: 1.5; }
        .notice svg { width: 27px; height: 27px; flex: 0 0 27px; margin-top: 3px; color: #b91c1c; }
        .right-title { color: #2d3748; font-size: 24px; line-height: 1.2; font-weight: 850; margin: 0; }
        .badge-art { margin-top: 28px; height: 330px; border-radius: 180px 0 0 180px; background: #0f172a; overflow: hidden; display: flex; justify-content: flex-end; align-items: center; }
        .badge-art img { width: 190px; height: 260px; object-fit: contain; margin-right: 36px; opacity: .9; }
        .action-list { margin-top: 44px; }
        .action-card { margin-top: 22px; overflow: hidden; border: 1px solid #e5e7eb; border-radius: 26px; background: white; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06); }
        .action-row { min-height: 72px; width: 100%; border: 0; border-bottom: 1px solid #f1f5f9; background: white; display: flex; align-items: center; justify-content: space-between; padding: 0 26px; color: #6b7280; text-align: left; font-size: 23px; font-weight: 500; }
        .action-row:last-child { border-bottom: 0; }
        .action-selected { border: 2px solid #10a36a; color: #1f2937; box-shadow: 0 0 0 5px rgba(16, 163, 106, 0.12); }
        .action-final { background: #e8fbf2; color: #109962; font-weight: 850; }
        .action-row svg { width: 24px; height: 24px; color: #109962; }
        @media (max-width: 1200px) {
            .sidebar { width: 350px; flex-basis: 350px; padding-left: 32px; }
            .side-inner { margin-left: 0; }
            .main-grid { grid-template-columns: 1fr; }
            .right-col { display: none; }
        }
    </style>
</head>
<body>
    <main class="prototype">
        <div class="warm-edge"></div>
        <div class="soft-panel"></div>
        <div class="top-line"></div>

        <div class="shell">
            <aside class="sidebar">
                <div class="side-inner">
                    <div class="user-row">
                        <div class="user-meta">
                            <div class="avatar">
                                <img src="{{ asset('logos/snake-green.svg') }}" alt="Kraite">
                            </div>
                            <div>
                                <div class="welcome">Welcome back,</div>
                                <div class="name">Bruno</div>
                            </div>
                        </div>
                        <button type="button" class="icon-button">
                            <x-feathericon-settings />
                        </button>
                    </div>

                    <div class="search">
                        <x-feathericon-search />
                        <span>Search</span>
                    </div>

                    <nav class="nav">
                        <a href="#" class="nav-item">
                            <span class="nav-left"><x-feathericon-home />Home</span>
                            <x-feathericon-menu class="nav-menu" />
                        </a>
                        <a href="#" class="nav-item">
                            <span class="nav-left"><x-feathericon-activity />Trades</span>
                            <span class="pill pill-green">0</span>
                        </a>
                        <a href="#" class="nav-item">
                            <span class="nav-left"><x-feathericon-credit-card />Billing</span>
                            <x-feathericon-menu class="nav-menu" />
                        </a>
                        <a href="#" class="nav-item">
                            <span class="nav-left"><x-feathericon-shield />Risk</span>
                            <x-feathericon-menu class="nav-menu" />
                        </a>
                        <a href="#" class="nav-item nav-active">
                            <span class="nav-left"><x-feathericon-info />Dashboard</span>
                            <x-feathericon-menu class="nav-menu" />
                        </a>
                        <a href="#" class="nav-item">
                            <span class="nav-left"><x-feathericon-wifi-off style="color:#dc2626" />Connection</span>
                            <span class="pill pill-red">FIX</span>
                        </a>
                    </nav>
                </div>
            </aside>

            <section class="content">
                <div class="content-inner">
                    <header class="header">
                        <div class="header-left">
                            <a href="{{ route('dashboard') }}" wire:navigate class="back">
                                <x-feathericon-arrow-left />
                            </a>
                            <div>
                                <div class="eyebrow">Prototype</div>
                                <h1>Trading Dashboard</h1>
                            </div>
                        </div>
                        <button type="button" class="icon-button">
                            <x-feathericon-bell />
                        </button>
                    </header>

                    <div class="main-grid">
                        <div>
                            <h2>Account information</h2>
                            <p class="lead">This account is set up, but trading stays paused until the exchange connection is fixed.</p>

                            <div class="field-group">
                                <label class="label">Exchange account</label>
                                <div class="input-like">
                                    Binance Account
                                    <span class="input-icon"><x-feathericon-briefcase /></span>
                                </div>
                            </div>

                            <div class="field-group">
                                <label class="label">Connection status</label>
                                <div class="choice-grid">
                                    <div class="choice choice-danger">
                                        <div>
                                            <div class="choice-kicker">Action required</div>
                                            <div class="choice-title">IP whitelist failed</div>
                                        </div>
                                        <button type="button" class="choice-action"><x-feathericon-x /></button>
                                    </div>
                                    <div class="choice choice-muted">
                                        <span class="radio"></span>
                                        <div>
                                            <div class="choice-kicker">Trading state</div>
                                            <div class="choice-title">Paused</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="field-group">
                                <label class="label">Current activity</label>
                                <div class="choice-grid">
                                    <div class="choice choice-muted">
                                        <span class="radio"></span>
                                        <div class="choice-title">No open trades</div>
                                    </div>
                                    <div class="choice choice-muted">
                                        <span class="radio"></span>
                                        <div class="choice-title">No margin in use</div>
                                    </div>
                                </div>
                            </div>

                            <div class="notice">
                                <x-feathericon-info />
                                <span>Add the Kraite IP addresses in Binance, then run the connection test again.</span>
                            </div>
                        </div>

                        <aside class="right-col">
                            <p class="right-title">Account badge</p>
                            <div class="badge-art">
                                <img src="{{ asset('logos/snake-green.svg') }}" alt="Kraite">
                            </div>

                            <div class="action-list">
                                <p class="right-title">Choose your action</p>
                                <div class="action-card">
                                    <button type="button" class="action-row action-selected">
                                        Fix connection
                                        <x-feathericon-wifi />
                                    </button>
                                    <button type="button" class="action-row">View positions</button>
                                    <button type="button" class="action-row">Billing</button>
                                    <button type="button" class="action-row">Risk settings</button>
                                    <button type="button" class="action-row action-final">Fix connection</button>
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
