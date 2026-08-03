import { useState, useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { getCreditPackages, createTopup, getTopupStatus } from "../services/toolService";
import { notify } from "../utils/notify";

export default function TopupPage() {
    const navigate = useNavigate();
    const [packages, setPackages] = useState([]);
    const [selected, setSelected] = useState(null);
    const [topupData, setTopupData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [creating, setCreating] = useState(false);
    const [status, setStatus] = useState(null);
    const pollRef = useRef(null);

    useEffect(() => {
        getCreditPackages()
            .then(res => setPackages(res.data?.packages || res.data || []))
            .catch(() => notify("Không thể tải gói credit", "error"))
            .finally(() => setLoading(false));
        return () => { if (pollRef.current) clearInterval(pollRef.current); };
    }, []);

    const handleCreate = async () => {
        if (!selected) { notify("Vui lòng chọn gói", "warning"); return; }
        setCreating(true);
        try {
            const res = await createTopup(selected);
            setTopupData(res.data);
            setStatus("pending");
            // Start polling
            pollRef.current = setInterval(async () => {
                try {
                    const s = await getTopupStatus(res.data.topup_id || res.data.id);
                    if (s.data?.status === "completed" || s.data?.status === "success") {
                        clearInterval(pollRef.current);
                        setStatus("completed");
                        notify("Nạp credit thành công! 🎉");
                    } else if (s.data?.status === "failed" || s.data?.status === "expired") {
                        clearInterval(pollRef.current);
                        setStatus("failed");
                        notify("Giao dịch thất bại hoặc hết hạn", "error");
                    }
                } catch { }
            }, 5000);
        } catch (e) { notify(e.response?.data?.message || "Tạo topup thất bại", "error"); }
        finally { setCreating(false); }
    };

    const handleBack = () => {
        if (pollRef.current) clearInterval(pollRef.current);
        setTopupData(null); setStatus(null); setSelected(null);
    };

    // QR + Payment View
    if (topupData) {
        return (
            <div className="animate-in" style={{ maxWidth: 600, margin: "0 auto" }}>
                <div className="page-header">
                    <button className="page-header-back" onClick={handleBack}>
                        <i className="fa-solid fa-arrow-left" />
                    </button>
                    <h1 className="page-title">Thanh toán</h1>
                </div>

                {status === "completed" ? (
                    <div className="glass-card" style={{ textAlign: "center", padding: 48 }}>
                        <i className="fa-solid fa-check-circle" style={{ fontSize: 64, color: "#10b981", marginBottom: 16 }} />
                        <h3 style={{ color: "var(--text-primary)", marginBottom: 8 }}>Nạp thành công!</h3>
                        <p style={{ color: "var(--text-muted)" }}>Credits đã được cộng vào tài khoản</p>
                        <button className="btn-primary-glow" onClick={() => navigate("/credits")} style={{ marginTop: 16 }}>
                            Xem Credits
                        </button>
                    </div>
                ) : status === "failed" ? (
                    <div className="glass-card" style={{ textAlign: "center", padding: 48 }}>
                        <i className="fa-solid fa-times-circle" style={{ fontSize: 64, color: "#ef4444", marginBottom: 16 }} />
                        <h3 style={{ color: "var(--text-primary)", marginBottom: 8 }}>Giao dịch thất bại</h3>
                        <button className="btn-glass" onClick={handleBack} style={{ marginTop: 16 }}>Thử lại</button>
                    </div>
                ) : (
                    <div className="qr-card">
                        <div style={{ marginBottom: 16, color: "var(--text-secondary)", fontSize: 13 }}>
                            Quét mã QR hoặc chuyển khoản theo thông tin bên dưới
                        </div>

                        {topupData.qr_url && (
                            <div className="qr-card__image">
                                <img src={topupData.qr_url} alt="QR Code" />
                            </div>
                        )}

                        <div className="qr-card__bank">
                            {topupData.bank_info?.bank_name && (
                                <div className="qr-card__bank-row">
                                    <span>Ngân hàng</span>
                                    <span style={{ fontWeight: 600 }}>{topupData.bank_info.bank_name}</span>
                                </div>
                            )}
                            {topupData.bank_info?.account_number && (
                                <div className="qr-card__bank-row">
                                    <span>Số tài khoản</span>
                                    <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
                                        <strong>{topupData.bank_info.account_number}</strong>
                                        <button className="btn-copy-sm" title="Copy" onClick={() => { navigator.clipboard.writeText(topupData.bank_info.account_number); notify("Đã copy số tài khoản", "success"); }}>
                                            <i className="fa-regular fa-copy" />
                                        </button>
                                    </span>
                                </div>
                            )}
                            {topupData.bank_info?.account_name && (
                                <div className="qr-card__bank-row">
                                    <span>Chủ tài khoản</span>
                                    <span style={{ fontWeight: 600 }}>{topupData.bank_info.account_name}</span>
                                </div>
                            )}
                            {topupData.bank_info?.amount && (
                                <div className="qr-card__bank-row">
                                    <span>Số tiền</span>
                                    <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
                                        <strong style={{ color: "#10b981", fontSize: 18 }}>
                                            {Number(topupData.bank_info.amount).toLocaleString()}đ
                                        </strong>
                                        <button className="btn-copy-sm" title="Copy" onClick={() => { navigator.clipboard.writeText(String(topupData.bank_info.amount)); notify("Đã copy số tiền", "success"); }}>
                                            <i className="fa-regular fa-copy" />
                                        </button>
                                    </span>
                                </div>
                            )}
                            {topupData.bank_info?.content && (
                                <div className="qr-card__bank-row">
                                    <span>Nội dung CK</span>
                                    <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
                                        <strong style={{ color: "#f59e0b" }}>{topupData.bank_info.content}</strong>
                                        <button className="btn-copy-sm" title="Copy" onClick={() => { navigator.clipboard.writeText(topupData.bank_info.content); notify("Đã copy nội dung chuyển khoản", "success"); }}>
                                            <i className="fa-regular fa-copy" />
                                        </button>
                                    </span>
                                </div>
                            )}
                        </div>

                        <div style={{
                            marginTop: 16, padding: "10px 14px",
                            background: "rgba(239, 68, 68, 0.1)", borderRadius: 8,
                            border: "1px solid rgba(239, 68, 68, 0.25)",
                            fontSize: 12, color: "#f87171", lineHeight: 1.5
                        }}>
                            <i className="fa-solid fa-triangle-exclamation" style={{ marginRight: 6 }} />
                            <strong>Lưu ý:</strong> Số tiền và nội dung chuyển khoản phải trùng khớp chính xác.
                            Nếu không, hệ thống sẽ <strong>không ghi nhận</strong> giao dịch.
                        </div>

                        <div style={{ marginTop: 20, textAlign: "center" }}>
                            <div className="loading-spinner" style={{ margin: "0 auto 8px" }} />
                            <div style={{ color: "var(--text-muted)", fontSize: 13 }}>Đang chờ thanh toán...</div>
                        </div>
                    </div>
                )}
            </div>
        );
    }

    // Package Selection
    return (
        <div className="animate-in">
            <div className="page-header">
                <h1 className="page-title"><i className="fa-solid fa-wallet" /> Nạp Credit</h1>
            </div>

            {loading ? (
                <div className="empty-state"><div className="loading-spinner" /><div className="loading-text">Đang tải...</div></div>
            ) : (
                <>
                    <div className="package-grid">
                        {packages.map(pkg => (
                            <div
                                key={pkg.id}
                                className={`package-card ${selected === pkg.id ? "selected" : ""}`}
                                onClick={() => setSelected(pkg.id)}
                            >
                                <div className="package-card__name">{pkg.name}</div>
                                <div className="package-card__price">{Number(pkg.price).toLocaleString()}đ</div>
                                <div className="package-card__credits">{Number(pkg.credits).toLocaleString()} credits</div>
                                <div style={{ fontSize: 12, color: "var(--text-muted)", marginTop: 4 }}>
                                    ≈ {Math.round((Number(pkg.credits) + (Number(pkg.bonus) || 0)) * 10 / 800)} phút TTS
                                </div>
                                {pkg.bonus && (
                                    <div className="badge-glass success" style={{ marginTop: 8 }}>
                                        +{pkg.bonus} bonus
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                    <div style={{ textAlign: "center", marginTop: 28 }}>
                        <button className="btn-primary-glow" onClick={handleCreate} disabled={!selected || creating}
                            style={{ padding: "12px 40px", fontSize: 14 }}>
                            {creating ? <><i className="fa-solid fa-spinner spinner" /> Đang tạo...</> : "Tiếp tục thanh toán"}
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
