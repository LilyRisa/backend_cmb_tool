# User Portal SPA Migration — Design

**Date:** 2026-08-04
**Status:** Approved (design), pending implementation plan

## Problem

The user-facing portal (login, register, OAuth handoff for the desktop app, dashboard, credits, TTS history, referral, topup, bug report, account settings) is currently served from the old ESP32 monolith at `G:\esp\ESP32_FULL\laravel`, on the domain `user.cmbcore.com`. That monolith also owns unrelated ESP32 device/audio/DSP/playlist features that this project (`cmbcoremkt_backend`) has deliberately excluded (see `docs/superpowers/specs/2026-07-30-extract-marketing-backend-design.md`).

The desktop client (`cmb_audio_tool_marketing`) opens a browser to this portal for OAuth login (`authHandlers.js` → `LOGIN_BASE`). We want the portal's backend fully migrated to `cmbcoremkt_backend`, and — per explicit decision — **hosted on `mkt.cmbcore.com`** (the same domain this backend already serves its API and admin panel from), not kept on a separate `user.cmbcore.com` domain.

## Decision: single domain, no `Route::domain()` wrapper

Unlike the old monolith (which used `Route::domain(env('TOOL_DOMAIN', 'user.cmbcore.com'))` to separate the portal from its marketing/admin routes), the new backend serves everything from one domain. `RouteServiceProvider::boot()` already registers `routes/api.php` (prefixed `api/`) *before* `routes/web.php`, so a catch-all route added at the very end of `web.php` is safe — Laravel matches the first-registered route for a given method+URI, so `/api/*` GET routes (`/api/me`, `/api/tool/credits`, etc.) resolve before the catch-all is ever reached. `/admin/*` routes are likewise registered earlier in `web.php` itself.

## Scope

Full portal, in one migration: Auth (login/register/OAuth handoff), Dashboard/Account, Credits, Topup, Referral, TTS History, Bug Report, Account Edit.

**Explicitly excluded** — all ESP32 device/audio/DSP/playlist functionality intermingled in the old SPA. This product no longer has devices, audio file management, or playlists.

## Backend API compatibility (already verified, no changes needed)

- `UserController@login/register/me` in the new backend already return `token`, `token_version`, `email_verified`, `avatar_url` — the exact shape the ported frontend expects.
- `OAuthController` in the new backend is already an identical port of the old one (`authorize` → one-time code → `/oauth/callback` → `cmbcoremkt://callback?token=...` redirect). `routes/web.php` already has `Route::get('/oauth/callback', ...)`.
- `toolService.js`'s endpoints (`/tool/credits`, `/tool/credits/transactions`, `/tool/credits/referral`, `/tool/credits/topup`, `/tool/tts/*`, `/tool/subscription`, `/tool/models`, `/tool/voices/*`) match `routes/api.php` 1:1.

The one gap: **no server-side Cloudflare Turnstile verification** in the new `UserController` (the old one has `verifyTurnstile()`, called from `login()`/`register()`, no-op when unconfigured, fails open on Cloudflare API errors). Port this for parity — otherwise the ported frontend's Turnstile widget is decorative.

## Routes (`routes/web.php`)

- Replace `Route::get('/', fn() => view('welcome'))` with `fn() => view('tool-spa')`.
- Add `Route::get('/login', ...)`, `Route::get('/register', ...)` → `view('tool-spa')`.
- Keep the existing `/oauth/callback` route untouched.
- Add `Route::get('/{any}', fn() => view('tool-spa'))->where('any', '.*')` as the **last** line in the file.

## New view: `resources/views/tool-spa.blade.php`

Adapted from the old `tool-spa.blade.php`: same Bootstrap/FontAwesome CDN links, `@viteReactRefresh` + `@vite(['resources/scss/app.scss', 'resources/js/react/tool-main.jsx'])`, and `window.__TURNSTILE_SITE_KEY` injected from `config('services.cloudflare_turnstile.site_key', '')`.

## Frontend source — copy plan

Source: `G:\esp\ESP32_FULL\laravel\resources\js\react\`. Destination: `cmbcoremkt_backend\resources\js\react\`.

**Verbatim copy:**
- `auth/useAuth.js`, `auth/AuthProvider.jsx`
- `services/api.js`, `services/toolService.js`
- `utils/notify.js`
- `pages/Auth.jsx`, `pages/EditAccountPage.jsx`, `pages/CreditsPage.jsx`, `pages/TopupPage.jsx`, `pages/ReferralPage.jsx`, `pages/TtsHistoryPage.jsx`, `pages/BugReportPage.jsx`

**Rewrite (strip device/audio entanglement):**
- `pages/AccountPage.jsx` — remove the Devices grid section, Recent Audio list section, and the Storage stat-card (`<StorageUsage compact />` + its `/audio/storage-usage` fetch). Keep the Credits stat-card, Package stat-card, and the Referral section.
- `components/Sidebar.jsx` — remove the "Thiết bị", "Audio", "Playlists" `NAV_ITEMS` entries. Keep Dashboard/Credits/TTS History/Nạp credit/Giới thiệu/Báo lỗi + the Cài đặt/Đăng xuất footer.
- `routes/AccountRoutes.jsx` — remove imports/routes for `AudioManagerPage`, `DeviceManagePage`, `PlaylistManagerPage`, `AudioDSP`; remove `AudioPlayerProvider`/`AudioPlayerBar` wiring from `AppLayout`.
- `tool-main.jsx` — drop the `AudioPlayerProvider` wrapper (keep `BrowserRouter` + `AuthProvider` + `AccountRoutes`).

**Excluded entirely (ESP32 device/audio-only):**
`components/DevicePanel.jsx`, `pages/AudioDSP.jsx`, `pages/DeviceManagePage.jsx`, `pages/AudioManagerPage.jsx`, `pages/PlaylistManagerPage.jsx`, `components/PlaylistCard.jsx`, `components/AppItem.jsx`, `components/AppGrid.jsx`, `components/AudioItem.jsx`, `components/AudioUploads.jsx`, `components/DeviceItem.jsx`, `components/AddDeviceModal.jsx`, `components/AddToPlaylistModal.jsx`, `components/AudioPlayerBar.jsx`, `components/StorageUsage.jsx`, `contexts/AudioPlayerContext.jsx`, `services/audioService.js`, `services/playlistService.js`, `utils/audioHelpers.js`.

## Styling

Copy `resources/scss/app.scss` and all its `@import`ed partials (`_variables`, `_reset`, `_typography`, `_layout`, `_buttons`, `_cards`, `_forms`, `_tables`, `_player`, `_auth`, `_dashboard`, `_utilities`, `_eq-preserve`) verbatim, unmodified. `_player`/`_eq-preserve` will contain some now-dead CSS classes for the dropped audio player bar — accepted as harmless bloat rather than risk breaking shared styles by hand-trimming.

## Build tooling

- `package.json`: add `react`, `react-dom`, `react-router-dom`, `sweetalert2`, `@vitejs/plugin-react` (devDependency, matching old repo), `sass` (devDependency, needed for Vite to compile `.scss`).
- `vite.config.js`: add the `react()` plugin; register `resources/scss/app.scss` and `resources/js/react/tool-main.jsx` as entry points (alongside the existing `resources/css/app.css`/`resources/js/app.js`, which stay for the admin panel).

## Config

- `config/services.php`: add
  ```php
  'cloudflare_turnstile' => [
      'site_key' => env('CLOUDFLARE_CAPTCHA_SITE_KEY'),
      'secret_key' => env('CLOUDFLARE_CAPTCHA_SECRET_KEY'),
  ],
  ```
- `.env.example`: add `CLOUDFLARE_CAPTCHA_SITE_KEY=` and `CLOUDFLARE_CAPTCHA_SECRET_KEY=`.

## Backend change: Turnstile verification

Port `verifyTurnstile(Request $request): ?JsonResponse` from the old `UserController` into the new one verbatim (calls `https://challenges.cloudflare.com/turnstile/v0/siteverify`, no-ops when `secret_key` is empty, fails open on HTTP/API errors, logs failures). Call it at the top of `login()` and `register()`.

## Desktop client changes (`cmb_audio_tool_marketing`)

- `src/main/ipc/authHandlers.js`: `LOGIN_BASE = 'https://user.cmbcore.com/login'` → `'https://mkt.cmbcore.com/login'`.
- `src/renderer/pages/Login/Login.jsx`: `handleRegister` URL `'https://user.cmbcore.com'` → `'https://mkt.cmbcore.com/register'`.
- `src/main/config/apiConfig.js`: update the header comment (currently says auth flows through a separate `cmbcore.com` OAuth ecosystem via `user.cmbcore.com`) to reflect that auth and API now share the same `mkt.cmbcore.com` host.

## Testing

New `tests/Feature/ToolSpaRouteTest.php` in `cmbcoremkt_backend`:
- `GET /`, `GET /login`, `GET /register`, and an arbitrary unmatched path (e.g. `GET /whatever/nested`) all return 200 and render the `tool-spa` view.
- `GET /admin/login` still resolves to the admin login view (not swallowed by the catch-all).
- `GET /api/me` (unauthenticated) still returns the API's own 401 JSON, not the SPA HTML — proves catch-all ordering is safe.

## Out of scope / explicitly deferred

- No changes to any ESP32 device/audio/DSP/playlist code, in either repo.
- No attempt to trim "dead" SCSS classes left over from the dropped audio player.
- No change to the existing `/register` vs `/login` tab-selection quirk in `Auth.jsx` (both routes render the same default "login" tab unless `?ref=` is present) — preserved as-is from the original.
- Decommissioning the old ESP32 monolith's `user.cmbcore.com` domain/DNS is an infra step outside this repo's scope — not addressed here.
