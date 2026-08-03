import { useState, useEffect } from "react";
import { getTtsHistory, deleteTtsHistory } from "../services/toolService";
import { notify } from "../utils/notify";
import Swal from "sweetalert2";

export default function TtsHistoryPage() {
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(true);

    useEffect(() => { loadHistory(); }, [page]);

    const loadHistory = async () => {
        try {
            setLoading(true);
            const res = await getTtsHistory(page, 20);
            const data = res.data?.tasks || res.data?.data || res.data || [];
            setItems(Array.isArray(data) ? data : []);
            setHasMore(res.data?.has_more ?? data.length >= 20);
        } catch { notify("Không thể tải lịch sử TTS", "error"); }
        finally { setLoading(false); }
    };

    const handleDelete = async (id) => {
        const r = await Swal.fire({
            title: "Xóa?", text: "Xóa mục TTS này?",
            icon: "warning", showCancelButton: true,
            confirmButtonColor: "#ef4444", cancelButtonColor: "#333",
            confirmButtonText: "Xóa", cancelButtonText: "Hủy",
            background: "#1a1a24", color: "#f0f0f5",
        });
        if (!r.isConfirmed) return;
        try { await deleteTtsHistory(id); notify("Đã xóa"); await loadHistory(); }
        catch (e) { notify(e.message, "error"); }
    };

    const statusBadge = (status) => {
        const map = {
            completed: { cls: "success", icon: "fa-check", text: "Hoàn thành" },
            processing: { cls: "info", icon: "fa-spinner spinner", text: "Đang xử lý" },
            pending: { cls: "warning", icon: "fa-clock", text: "Đang chờ" },
            failed: { cls: "danger", icon: "fa-times", text: "Thất bại" },
        };
        const s = map[status] || map.pending;
        return (
            <span className={`badge-glass ${s.cls}`}>
                <i className={`fa-solid ${s.icon}`} /> {s.text}
            </span>
        );
    };

    return (
        <div className="animate-in">
            <div className="page-header">
                <h1 className="page-title"><i className="fa-solid fa-clock-rotate-left" /> TTS History</h1>
                <div className="page-header-actions">
                    <button className="btn-glass" onClick={loadHistory}><i className="fa-solid fa-sync" /></button>
                </div>
            </div>

            <div className="tts-retention-notice" style={{
                display: "flex", alignItems: "center", gap: 10,
                padding: "10px 16px", marginBottom: 16, borderRadius: 10,
                background: "rgba(245, 158, 11, 0.08)",
                border: "1px solid rgba(245, 158, 11, 0.25)",
                color: "var(--text-secondary)", fontSize: 13,
            }}>
                <i className="fa-solid fa-circle-info" style={{ color: "#f59e0b", fontSize: 16, flexShrink: 0 }} />
                <span>Lịch sử TTS chỉ được lưu trữ tối đa <strong style={{ color: "#f59e0b" }}>48 giờ</strong>. Vui lòng tải audio về trước khi hết hạn.</span>
            </div>

            {loading ? (
                <div className="empty-state"><div className="loading-spinner" /><div className="loading-text">Đang tải...</div></div>
            ) : items.length === 0 ? (
                <div className="empty-state">
                    <div className="empty-state__icon"><i className="fa-solid fa-clock-rotate-left" /></div>
                    <div className="empty-state__title">Chưa có lịch sử TTS</div>
                    <div className="empty-state__text">Tạo giọng nói từ phần mềm desktop để xem lịch sử ở đây</div>
                </div>
            ) : (
                <>
                    {items.map((item, i) => (
                        <div key={item.id || i} className="tts-item animate-in">
                            <div className="tts-item__header">
                                {statusBadge(item.status)}
                                <button className="btn-icon danger" onClick={() => handleDelete(item.id)} title="Xóa">
                                    <i className="fa-solid fa-trash" />
                                </button>
                            </div>
                            <div className="tts-item__body">
                                {item.text || item.input_text || "—"}
                            </div>
                            <div className="tts-item__footer">
                                {item.provider && <span><i className="fa-solid fa-microphone" /> {item.provider}</span>}
                                {item.voice_id && <span><i className="fa-solid fa-user" /> {item.voice_id}</span>}
                                {item.credits_deducted_user > 0 && <span><i className="fa-solid fa-coins" /> {item.credits_deducted_user} credits</span>}
                                <span style={{ marginLeft: "auto" }}>
                                    {item.created_at ? new Date(item.created_at).toLocaleString("vi-VN") : ""}
                                </span>
                            </div>
                            {item.audio_url && item.status === "completed" && (
                                <div style={{ marginTop: 8 }}>
                                    <audio controls src={item.audio_url} style={{ width: "100%", height: 32 }} />
                                </div>
                            )}
                        </div>
                    ))}

                    <div style={{ display: "flex", justifyContent: "center", gap: 8, marginTop: 16 }}>
                        <button className="btn-glass" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
                            <i className="fa-solid fa-chevron-left" /> Trước
                        </button>
                        <span style={{ padding: "8px 16px", color: "var(--text-muted)", fontSize: 13 }}>Trang {page}</span>
                        <button className="btn-glass" disabled={!hasMore} onClick={() => setPage(p => p + 1)}>
                            Sau <i className="fa-solid fa-chevron-right" />
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
