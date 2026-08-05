# Admin Dashboard Overhaul — Design

## Status
Approved

## Goal
Replace the current 3-stat-card admin dashboard with a comprehensive overview: more stat cards, usage trend charts, and leaderboard/activity tables — porting the pattern already proven in the old backend (`G:\esp\ESP32_FULL\laravel`), adapted to this product's actual models (no Device/DspPreset/AudioFile — those are ESP32-only and don't exist here).

## Scope (confirmed with user)
- Full port of stat cards, trend charts, and tables — everything that maps to an existing model in this backend.
- Suspicious-IP preview card included on the dashboard (duplicating a summary of the existing `admin.analytics.ip` page is acceptable — it's a quick-glance widget, full detail stays on its own page).
- Trend charts use a fixed 30-day window (no range selector).
- Drop the old backend's "legacy row" (Devices, DSP Presets, Audio Files) — not applicable, no equivalent models exist in this backend.

## Data (`AdminController::dashboard()`)

All queries follow patterns already used elsewhere in this codebase (`ToolStatsController`, `UserAnalyticsController`) — same subquery/groupBy/havingRaw idioms, just consolidated onto one page.

**Stat cards:**
- `totalUsers` — `User::count()`
- `premiumUsers` — `User::currentlyPremium()->count()`
- `newUsersToday` — `User::whereDate('created_at', today)->count()`
- `creditsToday` / `creditsThisWeek` / `creditsThisMonth` — `abs(CreditTransaction::where('type','deduct')->...->sum('amount'))`
- `totalTtsRequests` / `ttsToday` — `TtsHistory::count()` / `::whereDate(...)`
- `totalVideoDubJobs` / `videoDubToday` — `VideoDubJob::count()` / `::whereDate(...)`
- `pendingTopups` — `PendingCreditTopup::where('status', PendingCreditTopup::STATUS_PENDING)->count()`

**Trends (30 days, missing dates filled with 0):**
- `creditTrend` — daily `abs(sum(amount))` from `CreditTransaction` where `type = deduct`
- `ttsTrend` — daily count from `TtsHistory`
- `loginTrend` — daily count from `LoginLog`

**Tables:**
- `topUsers` — top 10 users by total credit usage (deduct sum) + their TTS request count, via correlated subqueries (same pattern as `ToolStatsController::index`)
- `recentLogins` — latest 10 `LoginLog` rows with `user` eager-loaded
- `suspiciousIPs` — top 10 IPs from `LoginLog` where `action = register`, grouped by IP, `having distinct user_id > 1`, ordered by user count desc (same query as `UserAnalyticsController::ipAnalysis`, capped at 10, linking to `admin.analytics.ip.detail`)
- `topFeatures` — top 10 from `FeatureUsage`, grouped by `feature_name`, `sum(usage_count)` as total usage, `count(distinct user_id)` as unique users

## View (`resources/views/admin/dashboard.blade.php`)

Row 1 — stat cards (reuse existing `admin._partials._stats-card` partial, no changes needed):
Users, Premium, Credits Today (subtitle: week/month), TTS Requests, Video Dub Jobs, Pending Topups.

Row 2 — charts:
- Credit + TTS dual-axis line chart (left: credits, right: TTS count)
- Login count bar chart

Row 3 — tables:
- Top Users (by credit usage)
- Recent Logins
- Suspicious IPs (badge color by severity: 2-3 users = warning, 4+ = danger; row click → IP detail page)

Row 4 — top features:
- Table (rank, feature name, total usage, unique users)
- Horizontal bar chart (usage count + unique users per feature)

## Charts implementation
Chart.js loaded via CDN in `admin/layout.blade.php` `@stack('styles')`/before `@stack('scripts')` — consistent with how jQuery/Bootstrap/SweetAlert2 are already loaded in this layout (no bundler entry needed for one page). Colors/axes/tooltips follow the `dataviz` skill's guidance rather than copying the old backend's inline chart config verbatim.

## Testing
Add/extend a feature test asserting `GET /admin/dashboard` (as an authenticated admin) returns 200 and the view has the new expected data keys, following whatever pattern existing admin feature tests use in this repo.

## Out of scope
- Range selector for charts (fixed 30 days only)
- Any new models/migrations — this uses only existing tables
- Device/DspPreset/AudioFile legacy row (no equivalent models)
