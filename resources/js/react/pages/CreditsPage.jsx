import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { getCreditsBalance, getCreditTransactions, getReferralInfo } from "../services/toolService";
import { notify } from "../utils/notify";

export default function CreditsPage() {
    const navigate = useNavigate();
    const [credits, setCredits] = useState(null);
    const [transactions, setTransactions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(true);
    const [filter, setFilter] = useState(null);
    const [referral, setReferral] = useState(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => { loadData(); }, []);
    useEffect(() => { loadTransactions(); }, [page, filter]);

    const loadData = async () => {
        try {
            const res = await getCreditsBalance();
            setCredits(res.data);
        } catch { notify("Không thể tải thông tin credits", "error"); }
        finally { setLoading(false); }

        // Load referral info
        try {
            const res = await getReferralInfo();
            setReferral(res.data);
        } catch { }
    };

    const loadTransactions = async () => {
        try {
            const res = await getCreditTransactions(page, 20, filter);
            const data = res.data?.data || res.data?.transactions || [];
            setTransactions(data);
            setHasMore(data.length >= 20);
        } catch { }
    };

    const copyReferralLink = () => {
        if (!referral?.referral_link) return;
        navigator.clipboard.writeText(referral.referral_link).then(() => {
            setCopied(true);
            notify("Đã sao chép link giới thiệu!", "success");
            setTimeout(() => setCopied(false), 2000);
        });
    };

    const filters = [
        { value: null, label: "Tất cả" },
        { value: "topup", label: "Nạp tiền" },
        { value: "usage", label: "Sử dụng" },
    ];

    return (
        <div className="animate-in">
            <div className="page-header">
                <h1 className="page-title"><i className="fa-solid fa-coins" /> Credits</h1>
                <div className="page-header-actions">
                    <button className="btn-primary-glow" onClick={() => navigate("/topup")}>
                        <i className="fa-solid fa-wallet" /> Nạp thêm
                    </button>
                </div>
            </div>

            {/* Stats */}
            <div className="grid-stats" style={{ gridTemplateColumns: "repeat(3, 1fr)" }}>
                <div className="stat-card green">
                    <div className="stat-card__label">Số dư hiện tại</div>
                    <div className="stat-card__value">
                        {credits?.credits != null ? Number(credits.credits).toLocaleString() : "—"}
                    </div>
                    <div className="stat-card__sub">credits</div>
                </div>
                <div className="stat-card blue">
                    <div className="stat-card__label">Ước tính</div>
                    <div className="stat-card__value">{credits?.minutes_remaining || 0}</div>
                    <div className="stat-card__sub">phút TTS</div>
                </div>
                <div className="stat-card indigo">
                    <div className="stat-card__label">Gói hiện tại</div>
                    <div className="stat-card__value" style={{ fontSize: 18 }}>
                        {credits?.package_type
                            ? credits.package_type.charAt(0).toUpperCase() + credits.package_type.slice(1)
                            : "Free"}
                    </div>
                    <div className="stat-card__sub">
                        {credits?.package_expires_at ? `Hết hạn: ${new Date(credits.package_expires_at).toLocaleDateString("vi-VN")}` : ""}
                    </div>
                </div>
            </div>

            {/* Referral Section */}
            {referral && (() => {
                const refLink = `${window.location.origin}/register?ref=${referral.referral_code}`;
                return (<>
                    <div className="glass-card" style={{ marginTop: 16 }}>
                        <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 16 }}>
                            <div style={{
                                width: 40, height: 40, borderRadius: 12,
                                background: "linear-gradient(135deg, #f59e0b 0%, #ef4444 100%)",
                                display: "flex", alignItems: "center", justifyContent: "center",
                                flexShrink: 0,
                            }}>
                                <i className="fa-solid fa-gift" style={{ color: "#fff", fontSize: 18 }} />
                            </div>
                            <div>
                                <div className="section-title" style={{ margin: 0 }}>Giới thiệu bạn bè</div>
                                <div style={{ color: "var(--text-muted)", fontSize: 12, marginTop: 2 }}>
                                    Nhận <strong style={{ color: "#10b981" }}>800 credits</strong> (~10 phút TTS) khi bạn bè đăng ký
                                    &nbsp;·&nbsp;thêm <strong style={{ color: "#f59e0b" }}>10%</strong> khi họ mua credit
                                </div>
                            </div>
                        </div>

                        {/* Referral code */}
                        <div style={{ display: "flex", gap: 8, marginBottom: 10 }}>
                            <div style={{ flex: "0 0 auto" }}>
                                <div style={{ fontSize: 10, color: "var(--text-muted)", marginBottom: 3, textTransform: "uppercase", letterSpacing: 1 }}>Mã giới thiệu</div>
                                <input
                                    type="text" readOnly disabled value={referral.referral_code || ""}
                                    style={{
                                        width: 140, background: "rgba(255,255,255,0.06)", border: "1px solid rgba(255,255,255,0.1)",
                                        borderRadius: 8, padding: "8px 12px", color: "#f59e0b", fontSize: 17,
                                        fontFamily: "monospace", fontWeight: 700, textAlign: "center", letterSpacing: 3,
                                        cursor: "default",
                                    }}
                                />
                            </div>
                        </div>

                        {/* Referral link */}
                        <div style={{
                            display: "flex", gap: 8, alignItems: "center",
                            background: "rgba(255,255,255,0.04)", borderRadius: 12,
                            padding: "10px 14px", border: "1px solid rgba(255,255,255,0.08)",
                        }}>
                            <i className="fa-solid fa-link" style={{ color: "var(--text-muted)", fontSize: 13 }} />
                            <input
                                type="text"
                                readOnly
                                value={refLink}
                                style={{
                                    flex: 1, background: "transparent", border: "none", outline: "none",
                                    color: "var(--text-primary)", fontSize: 13, fontFamily: "monospace",
                                }}
                                onClick={e => e.target.select()}
                            />
                            <button
                                className={copied ? "btn-glass-primary" : "btn-glass"}
                                style={{ padding: "6px 14px", fontSize: 12, whiteSpace: "nowrap" }}
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

                        {/* Referral stats */}
                        <div style={{
                            display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, marginTop: 14,
                        }}>
                            <div style={{
                                background: "rgba(16,185,129,0.06)", borderRadius: 10,
                                padding: "12px 16px", border: "1px solid rgba(16,185,129,0.12)",
                            }}>
                                <div style={{ fontSize: 11, color: "var(--text-muted)", marginBottom: 4 }}>Bạn bè đã mời</div>
                                <div style={{ fontSize: 22, fontWeight: 700, color: "#10b981" }}>
                                    {referral.total_referrals || 0}
                                </div>
                            </div>
                            <div style={{
                                background: "rgba(245,158,11,0.06)", borderRadius: 10,
                                padding: "12px 16px", border: "1px solid rgba(245,158,11,0.12)",
                            }}>
                                <div style={{ fontSize: 11, color: "var(--text-muted)", marginBottom: 4 }}>Credits đã nhận</div>
                                <div style={{ fontSize: 22, fontWeight: 700, color: "#f59e0b" }}>
                                    {referral.total_earned ? Number(referral.total_earned).toLocaleString() : 0}
                                </div>
                            </div>
                        </div>

                        {/* Recent referral earnings */}
                        {referral.recent_referrals?.length > 0 && (
                            <div style={{ marginTop: 14 }}>
                                <div style={{ fontSize: 12, color: "var(--text-muted)", marginBottom: 8 }}>Lịch sử nhận thưởng</div>
                                {referral.recent_referrals.map((r) => (
                                    <div key={r.id} style={{
                                        display: "flex", alignItems: "center", justifyContent: "space-between",
                                        padding: "8px 0", borderBottom: "1px solid rgba(255,255,255,0.04)",
                                        fontSize: 13,
                                    }}>
                                        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                                            <span className={`badge-glass ${r.type === "referral" ? "success" : "warning"}`}
                                                style={{ fontSize: 10, padding: "2px 8px" }}>
                                                {r.type === "referral" ? "Đăng ký" : "Hoa hồng"}
                                            </span>
                                            <span style={{ color: "var(--text-secondary)" }}>{r.description}</span>
                                        </div>
                                        <span style={{ fontWeight: 700, color: "#10b981" }}>+{r.amount}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </>);
            })()}

            {/* Transactions */}
            <div className="glass-card" style={{ marginTop: 16 }}>
                <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 16 }}>
                    <div className="section-title" style={{ margin: 0 }}>Lịch sử giao dịch</div>
                    <div style={{ display: "flex", gap: 6 }}>
                        {filters.map(f => (
                            <button
                                key={f.value || "all"}
                                className={filter === f.value ? "btn-glass-primary" : "btn-glass"}
                                style={{ padding: "4px 12px", fontSize: 12 }}
                                onClick={() => { setFilter(f.value); setPage(1); }}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                </div>

                {transactions.length === 0 ? (
                    <div className="empty-state">
                        <div className="empty-state__icon"><i className="fa-solid fa-receipt" /></div>
                        <div className="empty-state__title">Chưa có giao dịch</div>
                    </div>
                ) : (
                    <>
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Loại</th>
                                    <th>Mô tả</th>
                                    <th style={{ textAlign: "right" }}>Số lượng</th>
                                    <th>Thời gian</th>
                                </tr>
                            </thead>
                            <tbody>
                                {transactions.map((tx, i) => (
                                    <tr key={tx.id || i} className="animate-in">
                                        <td>
                                            <span className={`badge-glass ${tx.type === "topup" || tx.type === "referral" || tx.type === "referral_commission" || tx.amount > 0 ? "success" : "danger"}`}>
                                                {tx.type === "topup" || tx.amount > 0
                                                    ? (tx.type === "referral" ? "Giới thiệu" : tx.type === "referral_commission" ? "Hoa hồng" : "Nạp")
                                                    : "Sử dụng"}
                                            </span>
                                        </td>
                                        <td style={{ color: "var(--text-primary)" }}>{tx.description || tx.note || "—"}</td>
                                        <td style={{ textAlign: "right", fontWeight: 700, color: tx.amount > 0 ? "#10b981" : "#ef4444" }}>
                                            {tx.amount > 0 ? `+${tx.amount}` : tx.amount}
                                        </td>
                                        <td>{tx.created_at ? new Date(tx.created_at).toLocaleString("vi-VN") : "—"}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

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
        </div>
    );
}

