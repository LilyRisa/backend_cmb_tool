import { useState, useEffect } from "react";
import api from "../services/api";
import { notify } from "../utils/notify";

export default function BugReportPage() {
    const [description, setDescription] = useState("");
    const [screenshots, setScreenshots] = useState([]);
    const [previews, setPreviews] = useState([]);
    const [submitting, setSubmitting] = useState(false);
    const [history, setHistory] = useState([]);
    const [loadingHistory, setLoadingHistory] = useState(true);

    useEffect(() => {
        loadHistory();
    }, []);

    const loadHistory = async () => {
        try {
            const res = await api.get("/tool/bug_reports");
            setHistory(res.data?.data || []);
        } catch { }
        setLoadingHistory(false);
    };

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files).slice(0, 5);
        setScreenshots(files);
        setPreviews(files.map(f => URL.createObjectURL(f)));
    };

    const removeScreenshot = (index) => {
        setScreenshots(prev => prev.filter((_, i) => i !== index));
        setPreviews(prev => prev.filter((_, i) => i !== index));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!description.trim()) {
            notify("Vui lòng mô tả lỗi bạn gặp phải", "warning");
            return;
        }
        setSubmitting(true);
        try {
            const formData = new FormData();
            formData.append("description", description);
            formData.append("app_version", "web-1.0");
            formData.append("device_info", navigator.userAgent.substring(0, 200));
            screenshots.forEach(f => formData.append("screenshots[]", f));

            await api.post("/tool/bug_report", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });

            notify("Báo lỗi đã được gửi thành công! Cảm ơn bạn 🎉");
            setDescription("");
            setScreenshots([]);
            setPreviews([]);
            loadHistory();
        } catch (err) {
            notify(err.response?.data?.message || "Gửi báo lỗi thất bại", "error");
        }
        setSubmitting(false);
    };

    return (
        <div className="animate-in" style={{ maxWidth: 700, margin: "0 auto" }}>
            <div className="page-header">
                <h1 className="page-title"><i className="fa-solid fa-bug" /> Báo Cáo Lỗi</h1>
            </div>

            {/* Form */}
            <div className="glass-card" style={{ marginBottom: 24 }}>
                <form onSubmit={handleSubmit}>
                    <div style={{ marginBottom: 16 }}>
                        <label style={{ color: "var(--text-secondary)", fontSize: 13, marginBottom: 6, display: "block" }}>
                            Mô tả lỗi bạn gặp phải <span style={{ color: "#ef4444" }}>*</span>
                        </label>
                        <textarea
                            className="form-input"
                            rows={4}
                            placeholder="VD: Khi nhấn nút TTS, ứng dụng bị treo..."
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            style={{
                                width: "100%", background: "rgba(255,255,255,0.05)",
                                border: "1px solid rgba(255,255,255,0.1)", borderRadius: 8,
                                padding: "10px 14px", color: "var(--text-primary)", fontSize: 14,
                                resize: "vertical",
                            }}
                        />
                    </div>

                    <div style={{ marginBottom: 16 }}>
                        <label style={{ color: "var(--text-secondary)", fontSize: 13, marginBottom: 6, display: "block" }}>
                            Ảnh chụp màn hình (tối đa 5 ảnh)
                        </label>
                        <label className="btn-glass" style={{ cursor: "pointer", display: "inline-block" }}>
                            <i className="fa-solid fa-camera me-1" /> Chọn ảnh
                            <input
                                type="file" accept="image/*" multiple
                                onChange={handleFileChange}
                                style={{ display: "none" }}
                            />
                        </label>

                        {previews.length > 0 && (
                            <div style={{ display: "flex", flexWrap: "wrap", gap: 8, marginTop: 12 }}>
                                {previews.map((src, i) => (
                                    <div key={i} style={{ position: "relative" }}>
                                        <img src={src} alt="" style={{
                                            width: 80, height: 80, objectFit: "cover",
                                            borderRadius: 8, border: "1px solid rgba(255,255,255,0.1)",
                                        }} />
                                        <button type="button" onClick={() => removeScreenshot(i)} style={{
                                            position: "absolute", top: -6, right: -6,
                                            background: "#ef4444", color: "#fff", border: "none",
                                            borderRadius: "50%", width: 20, height: 20,
                                            fontSize: 11, cursor: "pointer", lineHeight: "20px",
                                        }}>✕</button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <button type="submit" className="btn-primary-glow" disabled={submitting}
                        style={{ padding: "10px 32px", fontSize: 14 }}>
                        {submitting
                            ? <><i className="fa-solid fa-spinner spinner" /> Đang gửi...</>
                            : <><i className="fa-solid fa-paper-plane me-1" /> Gửi báo lỗi</>
                        }
                    </button>
                </form>
            </div>

            {/* History */}
            <div>
                <h3 style={{ color: "var(--text-primary)", fontSize: 16, marginBottom: 12 }}>
                    <i className="fa-solid fa-clock-rotate-left me-1" /> Lịch sử báo lỗi
                </h3>
                {loadingHistory ? (
                    <div className="empty-state"><div className="loading-spinner" /></div>
                ) : history.length === 0 ? (
                    <div className="glass-card" style={{ textAlign: "center", padding: 32, color: "var(--text-muted)" }}>
                        Chưa có báo lỗi nào
                    </div>
                ) : (
                    history.map(r => (
                        <div key={r.id} className="glass-card" style={{ marginBottom: 10, padding: 16 }}>
                            <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 8 }}>
                                <small style={{ color: "var(--text-muted)" }}>
                                    {new Date(r.created_at).toLocaleString("vi-VN")}
                                </small>
                                <span style={{
                                    fontSize: 11, padding: "2px 8px", borderRadius: 4,
                                    background: r.status === "resolved" ? "rgba(16,185,129,0.15)" :
                                        r.status === "reviewed" ? "rgba(59,130,246,0.15)" :
                                            "rgba(245,158,11,0.15)",
                                    color: r.status === "resolved" ? "#10b981" :
                                        r.status === "reviewed" ? "#3b82f6" : "#f59e0b",
                                }}>
                                    {r.status === "resolved" ? "Đã xử lý" :
                                        r.status === "reviewed" ? "Đang xem xét" : "Chờ xử lý"}
                                </span>
                            </div>
                            <p style={{ color: "var(--text-primary)", fontSize: 14, margin: 0 }}>{r.description}</p>
                            {r.screenshot_count > 0 && (
                                <small style={{ color: "var(--text-muted)", marginTop: 6, display: "block" }}>
                                    <i className="fa-solid fa-image me-1" />{r.screenshot_count} ảnh đính kèm
                                </small>
                            )}
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
