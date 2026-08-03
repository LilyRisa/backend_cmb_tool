# User Portal SPA Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the CMB Core user portal (login, register, OAuth handoff for the desktop app, dashboard, credits, TTS history, referral, topup, bug report, account settings) from the old ESP32 monolith (`G:\esp\ESP32_FULL\laravel`) onto `cmbcoremkt_backend`, served from `mkt.cmbcore.com` — the same domain this backend already serves its API and admin panel from.

**Architecture:** Copy the portal's React SPA source into `cmbcoremkt_backend\resources\js\react`, stripping everything tied to the old monolith's ESP32 device/audio/DSP/playlist features (out of scope for this product). Wire it up with a single new Blade view (`tool-spa.blade.php`) and three new routes plus a `Route::fallback()` in `routes/web.php`, guarded so `/api/*` misses stay real 404s instead of being swallowed into the SPA. The backend's existing API (`UserController`, `OAuthController`, `/tool/*` routes) already matches what the frontend expects — the only backend behavior gap is server-side Cloudflare Turnstile verification, ported from the old `UserController`. The desktop client is updated last to point at the new domain.

**Tech Stack:** Laravel 10 (PHP), Vite + `laravel-vite-plugin`, React 19 + `react-router-dom` 7, PHPUnit (`RefreshDatabase`), SCSS (Dart Sass, no Tailwind in the copied styles).

## Global Constraints

- Single domain (`mkt.cmbcore.com`), no `Route::domain()` wrapper — unlike the old monolith. The catch-all is implemented via `Route::fallback()` (see Task 9) so it never shadows `/admin/*`, named routes, or routes registered after boot.
- `RouteServiceProvider::boot()` already registers `routes/api.php` (prefixed `api/`) before `routes/web.php`. **This ordering alone is not sufficient to protect `/api/*`** — an `api.php` route whose inline parameter constraint (e.g. `->where('id', '[0-9]+')`) fails to match still falls through to any later `Route::get('/{any}')`-style catch-all, because Laravel considers that route a non-match and keeps scanning. `Route::fallback()` combined with an explicit `request()->is('api/*')` guard (Task 9) is what actually makes `/api/*` safe, not registration order by itself.
- Exclude **all** ESP32 device/audio/DSP/playlist code from both the copy and the rewritten files — this product has no devices, audio file management, or playlists.
- This repo has no JS test runner configured (mirrors the sibling `cmb_audio_tool_marketing` project's stated "no automated test framework" posture for its own frontend). Frontend tasks are verified by `diff`/`grep` checks against the known-good source, and by `npm run build` succeeding — not by unit tests. PHP-side changes (routes, controller) get real PHPUnit feature tests.
- Copy SCSS partials verbatim, including now-unused classes for the dropped audio player (`_player.scss`, `_eq-preserve.scss`) — do not hand-trim.
- Preserve the existing `Auth.jsx` `/register` vs `/login` tab-selection quirk exactly as-is (both routes default to the "login" tab unless `?ref=` is present).
- Full source spec: `docs/superpowers/specs/2026-08-04-user-portal-spa-migration-design.md`.

---

### Task 1: Frontend build tooling

**Files:**
- Modify: `package.json`
- Modify: `vite.config.js`

**Interfaces:**
- Produces: a Vite build pipeline that compiles `resources/scss/app.scss` and `resources/js/react/tool-main.jsx` via `@vitejs/plugin-react`, alongside the existing admin `resources/css/app.css` / `resources/js/app.js` entries (untouched).

- [ ] **Step 1: Update `package.json`**

Replace the full file with:

```json
{
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite",
        "build": "vite build"
    },
    "devDependencies": {
        "@vitejs/plugin-react": "^4.7.0",
        "axios": "^1.6.4",
        "laravel-vite-plugin": "^1.0.0",
        "sass": "^1.97.3",
        "vite": "^5.0.0"
    },
    "dependencies": {
        "react": "^19.2.3",
        "react-dom": "^19.2.3",
        "react-router-dom": "^7.11.0",
        "sweetalert2": "^11.26.17"
    }
}
```

- [ ] **Step 2: Update `vite.config.js`**

Replace the full file with:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        react(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/scss/app.scss',
                'resources/js/react/tool-main.jsx',
            ],
            refresh: true,
        }),
    ],
});
```

- [ ] **Step 3: Verify JSON/JS syntax**

Run: `node -e "require('./package.json')"` — expect no output (valid JSON).
Run: `node --check vite.config.js` — expect no output (valid syntax). Note: this only checks syntax, not that imports resolve; imports resolve once Task 8 runs `npm install`.

- [ ] **Step 4: Commit**

```bash
git add package.json vite.config.js
git commit -m "Add React/Vite tooling for the user portal SPA"
```

---

### Task 2: Copy the SCSS design system

**Files:**
- Create: `resources/scss/_variables.scss`, `_reset.scss`, `_typography.scss`, `_layout.scss`, `_buttons.scss`, `_cards.scss`, `_forms.scss`, `_tables.scss`, `_player.scss`, `_auth.scss`, `_dashboard.scss`, `_utilities.scss`, `_eq-preserve.scss`, `app.scss`
- Source: `G:\esp\ESP32_FULL\laravel\resources\scss\` (same filenames)

**Interfaces:**
- Produces: `resources/scss/app.scss`, the entry point Task 1's `vite.config.js` already references.

- [ ] **Step 1: Copy all partials and the entry point verbatim**

```bash
cd /d/cmbcoremkt_backend
for f in _variables.scss _reset.scss _typography.scss _layout.scss _buttons.scss _cards.scss _forms.scss _tables.scss _player.scss _auth.scss _dashboard.scss _utilities.scss _eq-preserve.scss app.scss; do
  cp "/g/esp/ESP32_FULL/laravel/resources/scss/$f" "resources/scss/$f"
done
```

- [ ] **Step 2: Verify byte-for-byte copies**

```bash
cd /d/cmbcoremkt_backend
for f in _variables.scss _reset.scss _typography.scss _layout.scss _buttons.scss _cards.scss _forms.scss _tables.scss _player.scss _auth.scss _dashboard.scss _utilities.scss _eq-preserve.scss app.scss; do
  diff "/g/esp/ESP32_FULL/laravel/resources/scss/$f" "resources/scss/$f" || echo "MISMATCH: $f"
done
```

Expected: no `MISMATCH` lines printed.

- [ ] **Step 3: Commit**

```bash
git add resources/scss
git commit -m "Copy user-portal SCSS design system from the ESP32 monolith"
```

---

### Task 3: Copy the unmodified React source

**Files:**
- Create: `resources/js/react/auth/useAuth.js`, `resources/js/react/auth/AuthProvider.jsx`
- Create: `resources/js/react/services/api.js`, `resources/js/react/services/toolService.js`
- Create: `resources/js/react/utils/notify.js`
- Create: `resources/js/react/pages/Auth.jsx`, `EditAccountPage.jsx`, `CreditsPage.jsx`, `TopupPage.jsx`, `ReferralPage.jsx`, `TtsHistoryPage.jsx`, `BugReportPage.jsx`
- Source: `G:\esp\ESP32_FULL\laravel\resources\js\react\` (same relative paths)

**Interfaces:**
- Produces: `useAuth()` hook, `AuthProvider` (exports `login`, `logout`, `refreshUser`, `user`, `loading`), default-exported `api` axios instance (baseURL `/api`, Bearer token from `localStorage`), `notify(msg, type)`, and page components `Auth`, `EditAccountPage`, `CreditsPage`, `TopupPage`, `ReferralPage`, `TtsHistoryPage`, `BugReportPage` — all consumed by Task 5's rewritten `AccountRoutes.jsx`.
- Consumes: nothing from earlier tasks (these files have no dependency on device/audio code, verified during design).

- [ ] **Step 1: Create directories and copy files verbatim**

```bash
cd /d/cmbcoremkt_backend
mkdir -p resources/js/react/auth resources/js/react/services resources/js/react/utils resources/js/react/pages

SRC=/g/esp/ESP32_FULL/laravel/resources/js/react

cp "$SRC/auth/useAuth.js" resources/js/react/auth/useAuth.js
cp "$SRC/auth/AuthProvider.jsx" resources/js/react/auth/AuthProvider.jsx
cp "$SRC/services/api.js" resources/js/react/services/api.js
cp "$SRC/services/toolService.js" resources/js/react/services/toolService.js
cp "$SRC/utils/notify.js" resources/js/react/utils/notify.js
cp "$SRC/pages/Auth.jsx" resources/js/react/pages/Auth.jsx
cp "$SRC/pages/EditAccountPage.jsx" resources/js/react/pages/EditAccountPage.jsx
cp "$SRC/pages/CreditsPage.jsx" resources/js/react/pages/CreditsPage.jsx
cp "$SRC/pages/TopupPage.jsx" resources/js/react/pages/TopupPage.jsx
cp "$SRC/pages/ReferralPage.jsx" resources/js/react/pages/ReferralPage.jsx
cp "$SRC/pages/TtsHistoryPage.jsx" resources/js/react/pages/TtsHistoryPage.jsx
cp "$SRC/pages/BugReportPage.jsx" resources/js/react/pages/BugReportPage.jsx
```

- [ ] **Step 2: Verify byte-for-byte copies**

```bash
cd /d/cmbcoremkt_backend
SRC=/g/esp/ESP32_FULL/laravel/resources/js/react
for f in auth/useAuth.js auth/AuthProvider.jsx services/api.js services/toolService.js utils/notify.js \
         pages/Auth.jsx pages/EditAccountPage.jsx pages/CreditsPage.jsx pages/TopupPage.jsx \
         pages/ReferralPage.jsx pages/TtsHistoryPage.jsx pages/BugReportPage.jsx; do
  diff "$SRC/$f" "resources/js/react/$f" || echo "MISMATCH: $f"
done
```

Expected: no `MISMATCH` lines printed.

- [ ] **Step 3: Commit**

```bash
git add resources/js/react/auth resources/js/react/services resources/js/react/utils resources/js/react/pages
git commit -m "Copy auth, services, and non-device portal pages from the ESP32 monolith"
```

---

### Task 4: Rewrite `AccountPage.jsx` (drop devices/audio/storage)

**Files:**
- Create: `resources/js/react/pages/AccountPage.jsx`

**Interfaces:**
- Consumes: `useAuth()` from `../auth/useAuth` (Task 3), `api` from `../services/api` (Task 3), `getReferralInfo` from `../services/toolService` (Task 3).
- Produces: default-exported `AccountPage` component, routed at `/account` by Task 5's `AccountRoutes.jsx`.

- [ ] **Step 1: Write the file**

```jsx
import { useEffect, useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { useAuth } from "../auth/useAuth";
import api from "../services/api";
import { getReferralInfo } from "../services/toolService";

export default function AccountPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [credits, setCredits] = useState(null);
  const [referral, setReferral] = useState(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    // Load credits
    api.get("/tool/credits")
      .then(res => setCredits(res.data))
      .catch(() => { });

    // Load referral info
    getReferralInfo()
      .then(res => setReferral(res.data))
      .catch(() => { });
  }, []);

  const avatarUrl = user?.avatar_url
    || (user?.avatar ? `/storage/${user.avatar}` : "/images/avatar.png");

  return (
    <div className="animate-in">
      {/* Greeting */}
      <div className="dashboard__greeting">
        <h1>Xin chào, {user?.name || "User"} 👋</h1>
        <p>Quản lý tài khoản và credit của bạn</p>
      </div>

      {/* Stats Grid */}
      <div className="grid-stats">
        <div className="stat-card green">
          <div className="stat-card__icon" style={{ background: "rgba(16,185,129,0.12)", color: "#10b981" }}>
            <i className="fa-solid fa-coins" />
          </div>
          <div className="stat-card__label">Credits</div>
          <div className="stat-card__value">
            {credits?.credits != null ? Number(credits.credits).toLocaleString() : "—"}
          </div>
          <div className="stat-card__sub">
            ≈ {credits?.minutes_remaining || 0} phút TTS
          </div>
        </div>

        <div className="stat-card indigo">
          <div className="stat-card__icon" style={{ background: "rgba(99,102,241,0.12)", color: "#6366f1" }}>
            <i className="fa-solid fa-box-open" />
          </div>
          <div className="stat-card__label">Gói hiện tại</div>
          <div className="stat-card__value" style={{ fontSize: 20 }}>
            {credits?.package_type
              ? credits.package_type.charAt(0).toUpperCase() + credits.package_type.slice(1)
              : "Free"}
          </div>
          <div className="stat-card__sub">
            {credits?.package_expires_at
              ? `Hết hạn: ${new Date(credits.package_expires_at).toLocaleDateString("vi-VN")}`
              : "Không giới hạn"
            }
          </div>
        </div>
      </div>

      {/* Referral Section */}
      {referral && (() => {
        const refLink = `${window.location.origin}/register?ref=${referral.referral_code}`;
        return (
          <div className="glass-card" style={{ marginTop: 16 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 12 }}>
              <div style={{
                width: 36, height: 36, borderRadius: 10,
                background: "linear-gradient(135deg, #f59e0b 0%, #ef4444 100%)",
                display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0,
              }}>
                <i className="fa-solid fa-gift" style={{ color: "#fff", fontSize: 16 }} />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 600, color: "var(--text-primary)", fontSize: 14 }}>Giới thiệu bạn bè</div>
                <div style={{ color: "var(--text-muted)", fontSize: 11 }}>
                  Nhận <strong style={{ color: "#10b981" }}>800 credits</strong> (~10 phút) · thêm <strong style={{ color: "#f59e0b" }}>10%</strong> hoa hồng
                </div>
              </div>
              <Link to="/credits" className="btn-glass" style={{ fontSize: 11, padding: "4px 10px" }}>
                Chi tiết <i className="fa-solid fa-arrow-right" style={{ marginLeft: 4, fontSize: 9 }} />
              </Link>
            </div>

            {/* Referral code */}
            <div style={{ display: "flex", gap: 8, marginBottom: 8 }}>
              <div style={{ flex: "0 0 auto" }}>
                <div style={{ fontSize: 10, color: "var(--text-muted)", marginBottom: 3, textTransform: "uppercase", letterSpacing: 1 }}>Mã giới thiệu</div>
                <input
                  type="text" readOnly disabled value={referral.referral_code || ""}
                  style={{
                    width: 120, background: "rgba(255,255,255,0.06)", border: "1px solid rgba(255,255,255,0.1)",
                    borderRadius: 8, padding: "6px 10px", color: "#f59e0b", fontSize: 15,
                    fontFamily: "monospace", fontWeight: 700, textAlign: "center", letterSpacing: 2,
                    cursor: "default",
                  }}
                />
              </div>
            </div>

            {/* Referral link */}
            <div style={{
              display: "flex", gap: 8, alignItems: "center",
              background: "rgba(255,255,255,0.04)", borderRadius: 10,
              padding: "8px 12px", border: "1px solid rgba(255,255,255,0.08)",
            }}>
              <i className="fa-solid fa-link" style={{ color: "var(--text-muted)", fontSize: 12 }} />
              <input
                type="text" readOnly value={refLink}
                style={{
                  flex: 1, background: "transparent", border: "none", outline: "none",
                  color: "var(--text-primary)", fontSize: 12, fontFamily: "monospace",
                }}
                onClick={e => e.target.select()}
              />
              <button
                className={copied ? "btn-glass-primary" : "btn-glass"}
                style={{ padding: "4px 12px", fontSize: 11, whiteSpace: "nowrap" }}
                onClick={() => {
                  navigator.clipboard.writeText(refLink).then(() => {
                    setCopied(true);
                    setTimeout(() => setCopied(false), 2000);
                  });
                }}
              >
                <i className={copied ? "fa-solid fa-check" : "fa-regular fa-copy"} style={{ marginRight: 4 }} />
                {copied ? "Đã sao chép" : "Sao chép"}
              </button>
            </div>
            {(referral.total_referrals > 0 || referral.total_earned > 0) && (
              <div style={{ display: "flex", gap: 16, marginTop: 10, fontSize: 12 }}>
                <span style={{ color: "var(--text-muted)" }}>Đã mời: <strong style={{ color: "#10b981" }}>{referral.total_referrals}</strong></span>
                <span style={{ color: "var(--text-muted)" }}>Đã nhận: <strong style={{ color: "#f59e0b" }}>{Number(referral.total_earned).toLocaleString()} credits</strong></span>
              </div>
            )}
          </div>
        );
      })()}
    </div>
  );
}
```

- [ ] **Step 2: Verify device/audio references are gone**

```bash
cd /d/cmbcoremkt_backend
grep -E "StorageUsage|device|Device|audio/files|recentAudio" resources/js/react/pages/AccountPage.jsx
```

Expected: no output (exit code 1 / no matches).

- [ ] **Step 3: Commit**

```bash
git add resources/js/react/pages/AccountPage.jsx
git commit -m "Rewrite AccountPage without device/audio/storage sections"
```

---

### Task 5: Rewrite `Sidebar.jsx` (drop device/audio/playlist nav)

**Files:**
- Create: `resources/js/react/components/Sidebar.jsx`

**Interfaces:**
- Consumes: `useAuth()` from `../auth/useAuth` (Task 3), `api` from `../services/api` (Task 3), `sweetalert2` (Task 1 dependency).
- Produces: default-exported `Sidebar({ isOpen, onClose })`, used by Task 6's `AccountRoutes.jsx`.

- [ ] **Step 1: Write the file**

```jsx
import { NavLink, useNavigate } from "react-router-dom";
import { useAuth } from "../auth/useAuth";
import Swal from "sweetalert2";

const NAV_ITEMS = [
    { to: "/account", icon: "fa-table-cells", label: "Dashboard", end: true },
    { to: "/credits", icon: "fa-coins", label: "Credits" },
    { to: "/tts-history", icon: "fa-clock-rotate-left", label: "TTS History" },
    { to: "/topup", icon: "fa-wallet", label: "Nạp credit" },
    { to: "/referral", icon: "fa-gift", label: "Giới thiệu" },
    { to: "/bug-report", icon: "fa-bug", label: "Báo lỗi" },
];

export default function Sidebar({ isOpen, onClose }) {
    const navigate = useNavigate();
    const { user, logout } = useAuth();

    const handleLogout = async () => {
        const result = await Swal.fire({
            title: "Đăng xuất?",
            text: "Bạn có muốn đăng xuất khỏi tài khoản?",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#6366f1",
            cancelButtonColor: "#333",
            confirmButtonText: "Đăng xuất",
            cancelButtonText: "Hủy",
            reverseButtons: true,
            background: "#1a1a24",
            color: "#f0f0f5",
        });

        if (result.isConfirmed) {
            try { await logout(); } catch { }
            navigate("/login", { replace: true });
        }
    };

    const avatarUrl = user?.avatar_url
        || (user?.avatar ? `/storage/${user.avatar}` : "/images/avatar.png");

    return (
        <>
            <div
                className={`sidebar-overlay ${isOpen ? "show" : ""}`}
                onClick={onClose}
            />

            <aside className={`sidebar ${isOpen ? "open" : ""}`}>
                {/* Brand */}
                <div className="sidebar-brand">
                    <div className="sidebar-brand-logo">C</div>
                    <span className="sidebar-brand-text">CMB Core</span>
                </div>

                {/* Navigation */}
                <nav className="sidebar-nav">
                    <div className="nav-section-label">Menu</div>
                    {NAV_ITEMS.map((item) => (
                        <NavLink
                            key={item.to}
                            to={item.to}
                            end={item.end}
                            className={({ isActive }) =>
                                `nav-item ${isActive ? "active" : ""}`
                            }
                            onClick={onClose}
                        >
                            <i className={`fa-solid ${item.icon}`} />
                            <span>{item.label}</span>
                        </NavLink>
                    ))}

                    <div className="nav-section-label" style={{ marginTop: 8 }}>
                        Tài khoản
                    </div>
                    <NavLink
                        to="/account/edit"
                        className={({ isActive }) =>
                            `nav-item ${isActive ? "active" : ""}`
                        }
                        onClick={onClose}
                    >
                        <i className="fa-solid fa-user-gear" />
                        <span>Cài đặt</span>
                    </NavLink>

                    <div className="nav-item" onClick={handleLogout}>
                        <i className="fa-solid fa-right-from-bracket" style={{ color: "#ef4444" }} />
                        <span style={{ color: "#ef4444" }}>Đăng xuất</span>
                    </div>
                </nav>

                {/* Footer – User info */}
                <div className="sidebar-footer">
                    <div
                        className="sidebar-user"
                        onClick={() => { navigate("/account/edit"); onClose?.(); }}
                    >
                        <img
                            src={avatarUrl}
                            alt={user?.name || "User"}
                            className="sidebar-user-avatar"
                        />
                        <div className="sidebar-user-info">
                            <div className="sidebar-user-name">{user?.name || "User"}</div>
                            <div className="sidebar-user-email">{user?.email || ""}</div>
                        </div>
                    </div>
                </div>
            </aside>
        </>
    );
}
```

- [ ] **Step 2: Verify device/audio nav items are gone**

```bash
cd /d/cmbcoremkt_backend
grep -E "devices|audio/manager|playlists|Thiết bị|Playlists" resources/js/react/components/Sidebar.jsx
```

Expected: no output.

- [ ] **Step 3: Commit**

```bash
git add resources/js/react/components/Sidebar.jsx
git commit -m "Rewrite Sidebar without device/audio/playlist nav items"
```

---

### Task 6: Rewrite `AccountRoutes.jsx` and `tool-main.jsx` (drop device/audio routing)

**Files:**
- Create: `resources/js/react/routes/AccountRoutes.jsx`
- Create: `resources/js/react/tool-main.jsx`

**Interfaces:**
- Consumes: `AccountPage` (Task 4), `Sidebar` (Task 5), `useAuth`/`AuthProvider` (Task 3), `Auth`, `EditAccountPage`, `CreditsPage`, `TopupPage`, `ReferralPage`, `TtsHistoryPage`, `BugReportPage` (Task 3), `api` (Task 3).
- Produces: the app's root entry (`tool-main.jsx`, referenced by `vite.config.js` from Task 1 and by the Blade view in Task 9) and its route table (`AccountRoutes`).

- [ ] **Step 1: Write `resources/js/react/routes/AccountRoutes.jsx`**

```jsx
import { useState } from "react";
import { Routes, Route, Navigate, Outlet } from "react-router-dom";
import AccountPage from "../pages/AccountPage";
import EditAccountPage from "../pages/EditAccountPage";
import CreditsPage from "../pages/CreditsPage";
import TopupPage from "../pages/TopupPage";
import TtsHistoryPage from "../pages/TtsHistoryPage";
import ReferralPage from "../pages/ReferralPage";
import BugReportPage from "../pages/BugReportPage";
import { useAuth } from "../auth/useAuth";
import Auth from "../pages/Auth";
import Sidebar from "../components/Sidebar";

// ── Loading screen ──
function LoadingScreen() {
  return (
    <div className="loading-overlay">
      <div className="loading-box">
        <div className="loading-spinner" />
        <div className="loading-text">Đang tải...</div>
      </div>
    </div>
  );
}

// ── PrivateRoute guard ──
function PrivateRoute({ children }) {
  const { user, loading } = useAuth();
  if (loading) return <LoadingScreen />;
  return user ? children : <Navigate to="/login" replace />;
}

// ── App Layout with Sidebar ──
function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const { user, refreshUser } = useAuth();
  const [resending, setResending] = useState(false);
  const [resendMsg, setResendMsg] = useState("");

  const handleResend = async () => {
    setResending(true);
    setResendMsg("");
    try {
      const res = await (await import("../services/api")).default.post("/auth/resend-verification");
      setResendMsg(res.data.message || "Đã gửi lại email xác minh!");
      // Refresh user in case they verified between clicks
      refreshUser();
    } catch (err) {
      const msg = err.response?.data?.error || "Có lỗi xảy ra, vui lòng thử lại.";
      setResendMsg(msg);
    } finally {
      setResending(false);
    }
  };

  return (
    <div className="app-layout">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      {/* Mobile header */}
      <div className="mobile-header">
        <button
          className="hamburger-btn"
          onClick={() => setSidebarOpen(true)}
        >
          <i className="fa-solid fa-bars" />
        </button>
        <span style={{ fontWeight: 700, color: "#f0f0f5" }}>CMB Core</span>
        <div style={{ width: 40 }} />
      </div>

      <main className="main-content">
        {/* Email verification alert banner */}
        {user && !user.email_verified && (
          <div style={{
            background: "linear-gradient(135deg, #fef3c7, #fde68a)",
            borderLeft: "4px solid #f59e0b",
            borderRadius: "8px",
            padding: "14px 20px",
            margin: "0 0 20px 0",
            display: "flex",
            flexWrap: "wrap",
            alignItems: "center",
            gap: "12px",
            fontSize: "14px",
            color: "#92400e",
          }}>
            <i className="fa-solid fa-triangle-exclamation" style={{ fontSize: 16, color: "#d97706" }}></i>
            <div style={{ flex: 1, minWidth: 200 }}>
              <strong>Email chưa được xác minh.</strong>{" "}
              Các tính năng premium và sử dụng credit yêu cầu xác minh email. Vui lòng kiểm tra hộp thư của bạn.
            </div>
            <button
              onClick={handleResend}
              disabled={resending}
              style={{
                background: "#d97706",
                color: "#fff",
                border: "none",
                borderRadius: "6px",
                padding: "8px 16px",
                fontSize: "13px",
                fontWeight: 600,
                cursor: resending ? "not-allowed" : "pointer",
                opacity: resending ? 0.6 : 1,
                whiteSpace: "nowrap",
              }}
            >
              {resending ? "Đang gửi..." : "Gửi lại email xác minh"}
            </button>
            {resendMsg && (
              <div style={{ width: "100%", fontSize: 13, marginTop: 4, color: "#92400e" }}>{resendMsg}</div>
            )}
          </div>
        )}

        <Outlet />
      </main>
    </div>
  );
}

// ── Routes ──
export default function AccountRoutes() {
  const { user, loading } = useAuth();

  return (
    <Routes>
      {/* Root redirect */}
      <Route path="/" element={
        loading ? <LoadingScreen /> :
          user ? <Navigate to="/account" replace /> :
            <Navigate to="/login" replace />
      } />

      {/* Auth (no sidebar) */}
      <Route path="/login" element={<Auth />} />
      <Route path="/register" element={<Auth />} />

      {/* Private routes with sidebar layout */}
      <Route element={<PrivateRoute><AppLayout /></PrivateRoute>}>
        <Route path="/account" element={<AccountPage />} />
        <Route path="/account/edit" element={<EditAccountPage />} />
        <Route path="/credits" element={<CreditsPage />} />
        <Route path="/topup" element={<TopupPage />} />
        <Route path="/referral" element={<ReferralPage />} />
        <Route path="/tts-history" element={<TtsHistoryPage />} />
        <Route path="/bug-report" element={<BugReportPage />} />
      </Route>

      {/* Catch-all */}
      <Route path="*" element={
        user ? <Navigate to="/account" replace /> : <Navigate to="/login" replace />
      } />
    </Routes>
  );
}
```

- [ ] **Step 2: Write `resources/js/react/tool-main.jsx`**

```jsx
import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import AccountRoutes from "./routes/AccountRoutes";
import { AuthProvider } from "./auth/AuthProvider";

const el = document.getElementById("root");

if (el) {
    createRoot(el).render(
        <BrowserRouter basename="/">
            <AuthProvider>
                <AccountRoutes />
            </AuthProvider>
        </BrowserRouter>
    );
}
```

- [ ] **Step 3: Verify device/audio references are gone**

```bash
cd /d/cmbcoremkt_backend
grep -E "AudioManagerPage|DeviceManagePage|PlaylistManagerPage|AudioDSP|AudioPlayerBar|AudioPlayerProvider" \
  resources/js/react/routes/AccountRoutes.jsx resources/js/react/tool-main.jsx
```

Expected: no output.

- [ ] **Step 4: Commit**

```bash
git add resources/js/react/routes/AccountRoutes.jsx resources/js/react/tool-main.jsx
git commit -m "Rewrite AccountRoutes and tool-main entry without device/audio routing"
```

---

### Task 7: Install dependencies and build the frontend

**Files:**
- None created/modified — this task runs the tooling set up in Tasks 1-6.

**Interfaces:**
- Produces: `public/build/manifest.json`, required by the `@vite(...)` directive that Task 9's Blade view uses (including in the PHPUnit feature tests added in Task 10 — without a manifest, `@vite` throws in any environment, including `testing`).

- [ ] **Step 1: Install dependencies**

```bash
cd /d/cmbcoremkt_backend
npm install
```

Expected: exits 0, `node_modules/react`, `node_modules/react-router-dom`, `node_modules/sweetalert2`, `node_modules/@vitejs/plugin-react`, `node_modules/sass` all present.

- [ ] **Step 2: Build**

```bash
cd /d/cmbcoremkt_backend
npm run build
```

Expected: exits 0, no Vite/Sass/React compile errors.

- [ ] **Step 3: Verify the manifest exists and references both entries**

```bash
cd /d/cmbcoremkt_backend
grep -l "tool-main" public/build/manifest.json
grep -l "app.scss" public/build/manifest.json
```

Expected: both commands print `public/build/manifest.json`.

- [ ] **Step 4: Commit**

`public/build` is build output — check `.gitignore` before staging:

```bash
cd /d/cmbcoremkt_backend
git check-ignore -v public/build/manifest.json
```

If it prints a match (already ignored), there is nothing to commit for this step — proceed to Task 8. If it prints nothing (not ignored), add `/public/build` to `.gitignore` first, then commit that:

```bash
cd /d/cmbcoremkt_backend
echo "/public/build" >> .gitignore
git add .gitignore
git commit -m "Ignore Vite build output"
```

---

### Task 8: Create the `tool-spa` Blade view

**Files:**
- Create: `resources/views/tool-spa.blade.php`

**Interfaces:**
- Consumes: `config('services.cloudflare_turnstile.site_key')` — added in Task 10 (`config()` returns `null`/empty until then, which is safe: `Auth.jsx`'s `TURNSTILE_SITE_KEY` check already treats an empty value as "no captcha").
- Produces: the `tool-spa` view, rendered by the routes added in Task 9.

- [ ] **Step 1: Write the file**

```blade
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0a0f">
    <title>CMB Core - User Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <meta name="description" content="CMB Core User Portal - TTS, Credit Management, Voice Generation">

    @viteReactRefresh
    @vite(['resources/scss/app.scss', 'resources/js/react/tool-main.jsx'])
</head>

<body>

    <script>
        window.__TURNSTILE_SITE_KEY = "{{ config('services.cloudflare_turnstile.site_key', '') }}";
    </script>
    <div id="root"></div>

    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js"></script>
</body>

</html>
```

- [ ] **Step 2: Verify it renders standalone via artisan tinker**

```bash
cd /d/cmbcoremkt_backend
php artisan tinker --execute="echo view('tool-spa')->render() ? 'RENDER_OK' : 'RENDER_FAIL';"
```

Expected: prints `RENDER_OK` (this only works because Task 7 already produced `public/build/manifest.json` — if this fails with a Vite manifest exception, re-run Task 7 first).

- [ ] **Step 3: Commit**

```bash
git add resources/views/tool-spa.blade.php
git commit -m "Add tool-spa Blade view for the user portal SPA"
```

---

### Task 9: Wire the SPA routes into `routes/web.php`

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/ToolSpaRouteTest.php`

**Interfaces:**
- Consumes: the `tool-spa` view (Task 8).
- Produces: `GET /`, `GET /login`, `GET /register` render `tool-spa`; any unmatched `GET` path falls back to `tool-spa` via `Route::fallback()`, EXCEPT paths under `/api/*`, which fall back to a plain 404 instead — this is what protects `routes/api.php`'s constrained routes (e.g. `->where('id', '[0-9]+')`) from being swallowed when the constraint fails to match.

> **Revision note (post-implementation):** the first attempt at this task used `Route::get('/{any}', ...)->where('any', '.*')` as originally specified below. A full-suite regression run (Step 5) caught two real breakages this naive catch-all causes, neither of which "api.php loads first" actually prevents: (1) an `api.php` route whose inline `->where('id', '[0-9]+')` constraint fails to match (e.g. a non-numeric id) is treated by Laravel as a non-match and falls through to the catch-all instead of a 404 — breaking `ToolTtsControllerTest`; (2) routes registered on the router after boot (e.g. `MiddlewareTest`'s ad-hoc routes registered in `setUp()`) are shadowed by the catch-all, since Laravel matches in registration order and the catch-all is already in the collection by then. `Route::fallback()` fixes both: it only fires when no route — including ones registered after boot — matches at dispatch time, and an explicit `request()->is('api/*')` guard inside it keeps `/api/*` misses as real 404s instead of SPA HTML. The steps below reflect this corrected approach.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ToolSpaRouteTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class ToolSpaRouteTest extends TestCase
{
    public function test_root_renders_tool_spa(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_login_renders_tool_spa(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_register_renders_tool_spa(): void
    {
        $response = $this->get('/register');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_unmatched_path_falls_back_to_tool_spa(): void
    {
        $response = $this->get('/whatever/nested/path');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_admin_login_is_not_shadowed_by_catch_all(): void
    {
        $response = $this->get('/admin/login');
        $response->assertOk();
        $response->assertViewIs('admin.login');
    }

    public function test_api_me_is_not_shadowed_by_catch_all(): void
    {
        $response = $this->getJson('/api/me');
        $response->assertStatus(401);
    }

    public function test_unmatched_api_path_returns_404_not_spa(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist');
        $response->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ToolSpaRouteTest`
Expected: FAIL — `test_root_renders_tool_spa` fails because `/` currently renders `welcome`, not `tool-spa`; `test_login_renders_tool_spa`/`test_register_renders_tool_spa`/`test_unmatched_path_falls_back_to_tool_spa` fail with 404 (routes don't exist yet); `test_unmatched_api_path_returns_404_not_spa` already passes at baseline (Laravel's default behavior for a genuinely unmatched path is already 404 — this test exists to catch a regression, not to drive new behavior).

- [ ] **Step 3: Modify `routes/web.php`**

In `D:\cmbcoremkt_backend\routes\web.php`, replace:

```php
Route::get('/', function () {
    return view('welcome');
});
```

with:

```php
Route::get('/', function () {
    return view('tool-spa');
});

Route::get('/login', function () {
    return view('tool-spa');
});

Route::get('/register', function () {
    return view('tool-spa');
});
```

Then, at the very end of the file (after the `admin` prefix group's closing `});`), add:

```php
// User-portal SPA fallback — Route::fallback() (not a Route::get('/{any}')
// catch-all) so it only fires when NO route matches at dispatch time. This
// correctly defers to routes registered after boot (e.g. test helpers that
// register routes in setUp()), which a plain registration-order catch-all
// would shadow. The explicit /api/* guard keeps API misses — including an
// api.php route whose ->where(...) constraint fails to match — as a real
// 404 instead of swallowing them into the SPA view.
Route::fallback(function () {
    if (request()->is('api/*')) {
        abort(404);
    }
    return view('tool-spa');
});
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=ToolSpaRouteTest`
Expected: PASS, all 7 tests.

- [ ] **Step 5: Run the full existing test suite to check for regressions**

Run: `php artisan test`
Expected: PASS. In particular, confirm `tests/Feature/ExampleTest.php::test_the_application_returns_a_successful_response` (`GET /` → 200), `tests/Feature/Auth/OAuthTest.php` (which hits `GET /oauth/callback`), `tests/Feature/MiddlewareTest.php` (registers routes in `setUp()` — this is exactly the case `Route::fallback()` protects), and `tests/Feature/Tool/ToolTtsControllerTest.php` (has `->where('id', '[0-9]+')`-constrained routes — this is exactly the case the `/api/*` guard protects) all still pass.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/ToolSpaRouteTest.php
git commit -m "Serve the user-portal SPA from / /login /register via Route::fallback()"
```

---

### Task 10: Cloudflare Turnstile config and server-side verification

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `app/Http/Controllers/API/UserController.php`
- Test: `tests/Feature/Auth/TurnstileVerificationTest.php`

**Interfaces:**
- Produces: `verifyTurnstile(Request $request): ?\Illuminate\Http\JsonResponse` (private method on `UserController`), called from `login()` and `register()`. Returns `null` when verification passes or is unconfigured (no-op); returns a `422` JSON response with `error` and `code` (`turnstile_required` or `turnstile_failed`) when it fails.

- [ ] **Step 1: Add config**

In `D:\cmbcoremkt_backend\config\services.php`, add before the closing `];`:

```php
    'cloudflare_turnstile' => [
        'site_key' => env('CLOUDFLARE_CAPTCHA_SITE_KEY'),
        'secret_key' => env('CLOUDFLARE_CAPTCHA_SECRET_KEY'),
    ],
```

- [ ] **Step 2: Add `.env.example` entries**

Append to `D:\cmbcoremkt_backend\.env.example`:

```
CLOUDFLARE_CAPTCHA_SITE_KEY=
CLOUDFLARE_CAPTCHA_SECRET_KEY=
```

- [ ] **Step 3: Write the failing tests**

Create `tests/Feature/Auth/TurnstileVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_without_turnstile_token_when_not_configured(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => null]);

        User::factory()->create(['email' => 'nocaptcha@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'nocaptcha@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_rejects_missing_turnstile_token_when_configured(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);

        User::factory()->create(['email' => 'missingtoken@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'missingtoken@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_required');
    }

    public function test_login_rejects_invalid_turnstile_token(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200),
        ]);

        User::factory()->create(['email' => 'badtoken@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'badtoken@example.com',
            'password' => 'password',
            'cf_turnstile_token' => 'bad-token',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_failed');
    }

    public function test_login_succeeds_with_valid_turnstile_token(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        User::factory()->create(['email' => 'goodtoken@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'goodtoken@example.com',
            'password' => 'password',
            'cf_turnstile_token' => 'good-token',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_register_rejects_missing_turnstile_token_when_configured(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_required');
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php artisan test --filter=TurnstileVerificationTest`
Expected: FAIL — `test_login_rejects_missing_turnstile_token_when_configured`, `test_login_rejects_invalid_turnstile_token`, and `test_register_rejects_missing_turnstile_token_when_configured` fail because nothing gates on the token yet (they currently return 200/other, not 422). The other two tests already pass (no-op behavior is the current, unmodified behavior).

- [ ] **Step 5: Add `Http` import and the `verifyTurnstile()` method**

In `D:\cmbcoremkt_backend\app\Http\Controllers\API\UserController.php`, add to the `use` block (after `use Illuminate\Support\Facades\Hash;`):

```php
use Illuminate\Support\Facades\Http;
```

Then add this method to the `UserController` class (anywhere inside the class body, e.g. right before the closing `}` of the class):

```php
    /**
     * Verify Cloudflare Turnstile token.
     * Returns null if valid, or JsonResponse if invalid.
     */
    private function verifyTurnstile(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $secretKey = config('services.cloudflare_turnstile.secret_key');

        // Skip verification if not configured (dev environment)
        if (empty($secretKey)) {
            return null;
        }

        $token = $request->input('cf_turnstile_token');

        if (empty($token)) {
            return response()->json([
                'error' => 'Vui lòng xác thực captcha',
                'code' => 'turnstile_required',
            ], 422);
        }

        try {
            $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            $result = $response->json();

            if (!($result['success'] ?? false)) {
                Log::warning('Turnstile verification failed', [
                    'ip' => $request->ip(),
                    'error_codes' => $result['error-codes'] ?? [],
                ]);

                return response()->json([
                    'error' => 'Xác thực captcha không hợp lệ. Vui lòng thử lại.',
                    'code' => 'turnstile_failed',
                ], 422);
            }
        } catch (\Exception $e) {
            Log::error('Turnstile API error: ' . $e->getMessage());
            // Allow through on API error to not block users
            return null;
        }

        return null;
    }
```

- [ ] **Step 6: Call it from `login()` and `register()`**

At the top of `login(Request $request)`, immediately after the opening `{`, add:

```php
        // Verify Cloudflare Turnstile
        $turnstileError = $this->verifyTurnstile($request);
        if ($turnstileError) return $turnstileError;

```

At the top of `register(Request $request)`, immediately after the opening `{`, add the same two lines.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=TurnstileVerificationTest`
Expected: PASS, all 5 tests.

- [ ] **Step 8: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS, including `tests/Feature/Auth/RegisterLoginTest.php` and `tests/Feature/Auth/OAuthTest.php` (neither sends `cf_turnstile_token`, and the test environment has no `CLOUDFLARE_CAPTCHA_SECRET_KEY` configured by default, so `verifyTurnstile()` no-ops for them).

- [ ] **Step 9: Commit**

```bash
git add config/services.php .env.example app/Http/Controllers/API/UserController.php tests/Feature/Auth/TurnstileVerificationTest.php
git commit -m "Add server-side Cloudflare Turnstile verification to login/register"
```

---

### Task 11: Point the desktop client at the new domain

**Files:**
- Modify: `D:\cmb_audio_tool_marketing\src\main\ipc\authHandlers.js`
- Modify: `D:\cmb_audio_tool_marketing\src\renderer\pages\Login\Login.jsx`
- Modify: `D:\cmb_audio_tool_marketing\src\main\config\apiConfig.js`

**Interfaces:**
- Consumes: `mkt.cmbcore.com` now serving `/login`, `/register`, `/oauth/callback` (Tasks 8-9), deployed and reachable.

- [ ] **Step 1: Update `LOGIN_BASE`**

In `D:\cmb_audio_tool_marketing\src\main\ipc\authHandlers.js`, change:

```js
const LOGIN_BASE = 'https://user.cmbcore.com/login';
```

to:

```js
const LOGIN_BASE = 'https://mkt.cmbcore.com/login';
```

- [ ] **Step 2: Update the register link**

In `D:\cmb_audio_tool_marketing\src\renderer\pages\Login\Login.jsx`, change:

```js
    const handleRegister = () => {
        window.api.invoke('app:open-external', { url: 'https://user.cmbcore.com' });
    };
```

to:

```js
    const handleRegister = () => {
        window.api.invoke('app:open-external', { url: 'https://mkt.cmbcore.com/register' });
    };
```

- [ ] **Step 3: Update the stale comment in `apiConfig.js`**

In `D:\cmb_audio_tool_marketing\src\main\config\apiConfig.js`, change:

```js
/**
 * API Configuration — single source of truth for the backend API base URL.
 *
 * All services and IPC handlers import API_BASE from here.
 * Previously hardcoded as: http://160.22.161.214/api, then https://cmbaudio.com/api
 * (the old ESP32/monolith host). Auth already flows through the cmbcore.com
 * OAuth ecosystem (see authHandlers.js LOGIN_BASE), so tokens are issued by
 * the new backend — feature calls must hit the same backend to validate.
 */
```

to:

```js
/**
 * API Configuration — single source of truth for the backend API base URL.
 *
 * All services and IPC handlers import API_BASE from here.
 * Previously hardcoded as: http://160.22.161.214/api, then https://cmbaudio.com/api
 * (the old ESP32/monolith host). The user portal (login/register/OAuth
 * handoff — see authHandlers.js LOGIN_BASE) is served from the same
 * mkt.cmbcore.com host as this API, by the same backend.
 */
```

- [ ] **Step 4: Verify the old domain is gone from both files**

```bash
cd /d/cmb_audio_tool_marketing
grep -n "user.cmbcore.com" src/main/ipc/authHandlers.js src/renderer/pages/Login/Login.jsx
```

Expected: no output.

- [ ] **Step 5: Commit**

```bash
cd /d/cmb_audio_tool_marketing
git add src/main/ipc/authHandlers.js src/renderer/pages/Login/Login.jsx src/main/config/apiConfig.js
git commit -m "Point login/register at mkt.cmbcore.com now that it serves the user portal"
```

---

## Deployment note (not automated by this plan)

This plan produces working code and passing tests in both repos, but **does not** deploy `cmbcoremkt_backend` or touch DNS/infra for `mkt.cmbcore.com` — that's an operational step outside either repo, to be done once these commits are reviewed. Do not merge Task 11's client-side commit to a released build until the backend deployment is live, or existing users' login button will 404.
