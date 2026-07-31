# Extract CMB Core Marketing Backend — Design

## 1. Bối cảnh & mục tiêu

`G:\esp\ESP32_FULL\laravel` hiện là một Laravel monolith phục vụ 2 sản phẩm không liên quan nhau:

1. **Điều khiển thiết bị ESP32** (loa/DSP âm thanh): quản lý device, DSP preset, phát nhạc, streaming audio qua MQTT/WebSocket.
2. **CMB Core Tool** — phần mềm desktop tự động hoá marketing video (tìm kiếm → tải → chỉnh sửa → đăng tải video TikTok/Facebook/YouTube), có TTS, dịch/tạo phụ đề (SRT), dub video, sinh kịch bản AI, tìm stock media, quản lý credit/subscription, quản lý tài khoản Meta, auto comment Facebook, email campaign.

Mục tiêu: tách phần backend của **CMB Core Tool** ra thành một project Laravel độc lập tại `D:\cmbcoremkt_backend`, đồng thời bổ sung **1 API endpoint tạo hình ảnh AI mới** (gọi provider theo chuẩn OpenAI, base_url/api_key/model cấu hình được).

## 2. Kiến trúc & phương pháp tách

- Dựng **project Laravel 10 mới hoàn toàn** (không copy-rồi-xoá) tại `D:\cmbcoremkt_backend`, PHP ^8.1, tự cài lại các package cần thiết.
- Package cần thiết: `laravel/sanctum` (auth API), `jeroennoten/laravel-adminlte` (giữ đồng bộ UI admin với project gốc), `sepayvn/laravel-sepay` (thanh toán topup/subscription), `guzzlehttp/guzzle`.
- **Không** cài: `beyondcode/laravel-websockets`, `php-mqtt/client`, `textalk/websocket`, `react/promise` — toàn bộ là hạ tầng riêng của ESP32 (MQTT, WebSocket điều khiển thiết bị), marketing tool không dùng.
- Database, `.env`, `APP_KEY`, cấu hình Sanctum... hoàn toàn độc lập với project ESP32. Kể từ thời điểm tách, bảng `users` ở 2 hệ thống **phân kỳ độc lập** — không đồng bộ 2 chiều tự động sau này.
- Giữ nguyên hành vi các middleware đặc thù: `CheckTokenVersion` (alias `token.version`), `EnsureEmailIsVerified` (alias `email.verified`), `IsAdmin`.
- Scope: dựng cả **API** (phục vụ app desktop CMB Core Tool) và **Admin Panel** (blade + AdminLTE) cho các chức năng quản trị liên quan.

## 3. Ranh giới thành phần

### Chuyển sang `cmbcoremkt_backend`

| Loại | Thành phần |
|---|---|
| Models | `User` (bỏ mọi quan hệ tới Device/DSP/Audio/Playlist), `CreditTransaction`, `PendingCreditTopup`, `FeatureUsage`, `FeatureCreditUsage`, `Tool`, `TtsHistory`, `SrtGenerateJob`, `SrtTranslateJob`, `VideoDubJob`, `Subscription`, `PendingSubscriptionPayment`, `EmailCampaign`, `FbAutoCommentCampaign`, `MetaAccount`, `BugReport`, `ContactMessage`, `BlogPost`, `BlogCategory`, `SystemSetting`, `LoginLog`, `Preorder` |
| API Controllers | `UserController`, `OAuthController`, `AIController`, `VideoDubController`, `SrtTranslateController`, `SrtGenerateController`, `ToolTtsController`, `ToolVoiceController`, `ToolCreditController`, `ToolSubscriptionController`, `CreditTopupController`, `ToolFeatureCreditController`, `FbAutoCommentController`, `BugReportController`, `FeatureUsageController`, `ScriptController`, `SceneController`, `MetaAccountApiController`, `MetaAiController`, `UpdateCheckController` (chỉ giữ method `getCmbLatestVersion`/`getCmbVersionList`), **`ImageGenController` (mới)** — ~~`PexelsController`, `StockMediaController`~~ (bỏ khỏi scope, xem mục 5) |
| Web/Admin Controllers | `BlogController`, `PricingController`, `CustomerInquiryController`, `CmbController`, `AdminController`, `BlogManagementController`, `CmbManagementController`, `EmailCampaignController`, `InquiryManagementController`, `MetaAccountController`, `ToolManagementController`, `ToolSettingsController`, `ToolStatsController`, `UserAnalyticsController`, `UserManagementController`, `VideoDubManagementController` |
| Services | `CreditService`, `GeminiService`, `GenMaxService`, `GroqService`, `MetaAiService`, `OpenRouterService`, `PremiumService`, `SceneService`, `ScriptService`, `SePayService`, `SrtChunkTranslationService`, `SrtParserService`, `SrtTimeRedistributionService`, **`OpenAiImageService` (mới)** — ~~`PexelsService`, `StockMediaService`~~ (bỏ khỏi scope, xem mục 5) |
| Jobs | `ProcessSrtGenerate`, `ProcessSrtTranslate`, `ProcessVideoDub`, `SendCampaignEmails` |
| Routes | `/api/auth/*`, `/api/user/*`, `/me`, `/logout`, `/account/*`, `/transcribe`, `/translate`, `/api/tool/*` (bao gồm route mới `/api/tool/generate-image`), `/api/meta-account/*`, `/api/meta-ai/*`, `/api/cmb/*`, các trang web pricing/blog/liên hệ, và toàn bộ route admin tương ứng |
| Kèm theo | FormRequest, Mailable, Blade view tương ứng với mỗi controller ở trên được tái tạo song song |

### Ở lại `G:\esp\ESP32_FULL\laravel` (không chuyển)

`Device*`, `DspController`, `DspPreset`, `AudioController`, `AudioStreamController`, `AudioFile`, `PlaylistController`, `Playlist`, `StreamSession`, `DeviceEq`, `DeviceClaimCode`, `DeviceTransferRequest`, `DeviceMqttController`, `DeviceTransferController`, `DeviceManagementController`, `PresetManagementController`, `AudioManagementController`, `DeviceClaimService`, `AppDownloadController`/`AppDownloadManagementController`/`AppDownload` (app di động điều khiển thiết bị), toàn bộ `app/WebSockets`, `app/Http/Controllers/Socket`, cấu hình MQTT, `UpdateCheckController::getLatestFlashTool`.

## 4. Di chuyển dữ liệu thật (1 lần, thủ công)

- Viết Artisan command **trong project ESP32**: `php artisan export:marketing-data` — export ra file JSON/SQL các bảng: `users` (các cột liên quan credit/subscription/profile), `credit_transactions`, `pending_credit_topups`, `feature_usages`, `feature_credit_usages`, `tools`, `tts_histories`, `srt_generate_jobs`, `srt_translate_jobs`, `video_dub_jobs`, `subscriptions`, `pending_subscription_payments`, `email_campaigns`, `fb_auto_comment_campaigns`, `meta_accounts`, `bug_reports`, `contact_messages`, `blog_posts`, `blog_categories`, `system_settings`, `login_logs`, `preorders`.
- Viết Artisan command **trong project mới**: `php artisan import:marketing-data` — nạp dữ liệu, **giữ nguyên ID** để không vỡ khoá ngoại nội bộ (vd `credit_transactions.user_id`, `tts_histories.user_id`).
- Cột nào chỉ có ý nghĩa bên hệ ESP32 (nếu `users` có cột liên quan device) sẽ bị bỏ qua khi import.
- Chạy thủ công 1 lần, có bước xác nhận trước khi ghi đè DB đích. Không đồng bộ tự động/định kỳ về sau — 2 hệ dữ liệu phân kỳ độc lập kể từ đây.

## 5. API tạo hình ảnh mới (OpenAI-compatible)

> **Cập nhật (Phase 3D):** bỏ hoàn toàn `PexelsController`/`PexelsService` và
> `StockMediaController`/`StockMediaService` khỏi scope — thay bằng API tạo hình ảnh AI
> dưới đây. `ScriptController`/`ScriptService` và `SceneController`/`SceneService` giữ
> nguyên như thiết kế gốc. Giá credit/ảnh KHÔNG hardcode trong `CreditService` như mô tả
> ban đầu — admin tự đặt giá qua trang Tool Settings (xem cập nhật bên dưới).

### Settings (Admin > Tool Settings, section "Image Generation")

Theo pattern `SystemSetting::getValue/setValue` sẵn có (giống `genmax_api_key`):

- `image_gen_base_url` — mặc định `https://api.openai.com/v1`
- `image_gen_api_key` — mã hoá (`is_encrypted = true`)
- `image_gen_model` — mặc định `gpt-image-1`
- `image_gen_credits_per_image` — **(mới)** số credit/ảnh, admin tự đặt qua giao diện, mặc định 200 nếu chưa cấu hình. Không hardcode trong code — đây là tính năng hoàn toàn mới, chưa có giá tham chiếu từ hệ thống gốc.

Cho phép trỏ sang bất kỳ endpoint tương thích chuẩn OpenAI Images API nào (proxy, self-host, provider khác).

Trang admin quản lý các setting này thuộc `Admin\ToolSettingsController` (mới, 1 trang
index hiển thị + form cập nhật 4 giá trị trên), dùng chung `admin.layout` (Phase 3C).

### Service: `OpenAiImageService`

- `generate(string $prompt, string $size = '1024x1024', int $n = 1): array`
- Gọi `POST {base_url}/images/generations`, header `Authorization: Bearer {api_key}`, body `{ model, prompt, size, n }`.
- Nhận response `data[].b64_json` (ưu tiên) hoặc `data[].url`; nếu là base64 thì decode và lưu vào `storage/app/public/generated-images/{uuid}.png`; nếu là url thì tải về lưu cùng chỗ để có URL ổn định lâu dài.
- Trả về mảng URL public (`Storage::url(...)`).
- Ném `RuntimeException` khi thiếu API key hoặc provider trả lỗi (theo đúng pattern các Service khác).

### Controller: `API\ImageGenController::generate()`

- Route: `POST /api/tool/generate-image`, trong nhóm middleware `tool` hiện có (`auth:sanctum`, `token.version`) + `throttle:5,1,generate-image` (3rd-segment rõ ràng, theo quy ước của dự án) + `email.verified` — nhất quán với `generate-script`, `generate-scenes`.
- `GenerateImageRequest`: `prompt` (required, string, max 2000), `size` (nullable, in: 256x256,512x512,1024x1024), `n` (nullable, integer, 1–4, default 1).
- **Premium gate**: kiểm tra `isPremium()` ngay trong controller, giống hệt `ScriptController`/`SceneController` — đây là điểm chặn server-side duy nhất của endpoint này (xem mục credit bên dưới để biết lý do cần điểm chặn này).
- Gate truy cập theo credit: thêm feature key `image_generation` vào bảng giá của `CreditService` (số credit = `n` × `image_gen_credits_per_image`, đọc động từ `SystemSetting` — khác với `create_video_script` tính theo phút), dùng lại luồng `ToolFeatureCreditController` 2 pha (deduct-feature/confirm-feature) mà client app hiện đang gọi cho các feature khác — **không** deduct trực tiếp trong `ImageGenController` để nhất quán với cách app desktop đang tính phí các tool khác. Lưu ý: việc deduct/confirm hoàn toàn do client điều phối, backend không ép buộc trình tự gọi — đây là lý do premium gate ở trên là cần thiết để tránh user free gọi thẳng endpoint mà bỏ qua bước trừ credit phía client.
- Trả JSON: `{ success, data: { images: [url, ...] } }` hoặc `{ success: false, error }`.
- Không có model/API lịch sử ảnh riêng ở v1 (YAGNI) — bổ sung sau nếu cần giống `tts/history`.

## 6. Ngoài phạm vi (out of scope)

- Không đồng bộ 2 chiều dữ liệu users giữa 2 hệ sau khi tách.
- Không xây lại UI desktop app (chỉ backend/API).
- Không bật lại tính năng Meta AI image/video generation đang bị disable (giữ nguyên trạng thái comment, không phải nhiệm vụ của thiết kế này).
- Không có lịch sử ảnh đã tạo (history endpoint) ở v1.
