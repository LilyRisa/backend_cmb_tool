import { useState, useEffect } from "react";
import { getReferralInfo } from "../services/toolService";
import { notify } from "../utils/notify";

export default function ReferralPage() {
    const [referral, setReferral] = useState(null);
    const [loading, setLoading] = useState(true);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        getReferralInfo()
            .then(res => setReferral(res.data))
            .catch(() => notify("Không thể tải thông tin giới thiệu", "error"))
            .finally(() => setLoading(false));
    }, []);

    const refLink = referral ? `${window.location.origin}/register?ref=${referral.referral_code}` : "";

    const copyLink = () => {
        navigator.clipboard.writeText(refLink).then(() => {
            setCopied(true);
            notify("Đã sao chép link giới thiệu!", "success");
            setTimeout(() => setCopied(false), 2000);
        });
    };

    if (loading) return (
        <div className="animate-in" style={{ textAlign: "center", padding: 60 }}>
            <i className="fa-solid fa-spinner spinner" style={{ fontSize: 24 }} />
        </div>
    );

    return (
        <div className="animate-in">
            <div className="page-header">
                <h1 className="page-title"><i className="fa-solid fa-gift" /> Giới thiệu bạn bè</h1>
            </div>

            {/* How it works */}
            <div className="glass-card">
                <div className="section-title" style={{ margin: "0 0 16px" }}>Cách hoạt động</div>
                <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: 16 }}>
                    {[
                        { icon: "fa-link", color: "#6366f1", title: "1. Chia sẻ link", desc: "Gửi link giới thiệu cho bạn bè" },
                        { icon: "fa-user-plus", color: "#10b981", title: "2. Bạn bè đăng ký", desc: "Bạn nhận 800 credits (~10 phút TTS)" },
                        { icon: "fa-cart-shopping", color: "#f59e0b", title: "3. Bạn bè mua credit", desc: "Bạn nhận thêm 10% hoa hồng" },
                    ].map((step, i) => (
                        <div key={i} style={{
                            background: "rgba(255,255,255,0.03)", borderRadius: 12,
                            padding: 20, border: "1px solid rgba(255,255,255,0.06)",
                            textAlign: "center",
                        }}>
                            <div style={{
                                width: 48, height: 48, borderRadius: 14, margin: "0 auto 12px",
                                background: `${step.color}18`, display: "flex", alignItems: "center", justifyContent: "center",
                            }}>
                                <i className={`fa-solid ${step.icon}`} style={{ color: step.color, fontSize: 20 }} />
                            </div>
                            <div style={{ fontWeight: 700, color: "var(--text-primary)", marginBottom: 4, fontSize: 14 }}>{step.title}</div>
                            <div style={{ color: "var(--text-muted)", fontSize: 12 }}>{step.desc}</div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Referral Code & Link */}
            {referral && (
                <div className="glass-card" style={{ marginTop: 16 }}>
                    <div className="section-title" style={{ margin: "0 0 16px" }}>Link giới thiệu của bạn</div>

                    {/* Code */}
                    <div style={{ marginBottom: 14 }}>
                        <div style={{ fontSize: 10, color: "var(--text-muted)", marginBottom: 4, textTransform: "uppercase", letterSpacing: 1 }}>Mã giới thiệu</div>
                        <input
                            type="text" readOnly disabled value={referral.referral_code || ""}
                            style={{
                                width: 160, background: "rgba(255,255,255,0.06)", border: "1px solid rgba(255,255,255,0.1)",
                                borderRadius: 10, padding: "10px 14px", color: "#f59e0b", fontSize: 20,
                                fontFamily: "monospace", fontWeight: 700, textAlign: "center", letterSpacing: 4,
                                cursor: "default",
                            }}
                        />
                    </div>

                    {/* Link */}
                    <div style={{ fontSize: 10, color: "var(--text-muted)", marginBottom: 4, textTransform: "uppercase", letterSpacing: 1 }}>Link giới thiệu</div>
                    <div style={{
                        display: "flex", gap: 8, alignItems: "center",
                        background: "rgba(255,255,255,0.04)", borderRadius: 12,
                        padding: "10px 14px", border: "1px solid rgba(255,255,255,0.08)",
                    }}>
                        <i className="fa-solid fa-link" style={{ color: "var(--text-muted)", fontSize: 13 }} />
                        <input
                            type="text" readOnly value={refLink}
                            style={{
                                flex: 1, background: "transparent", border: "none", outline: "none",
                                color: "var(--text-primary)", fontSize: 13, fontFamily: "monospace",
                            }}
                            onClick={e => e.target.select()}
                        />
                        <button
                            className={copied ? "btn-glass-primary" : "btn-glass"}
                            style={{ padding: "8px 18px", fontSize: 13, whiteSpace: "nowrap" }}
                            onClick={copyLink}
                        >
                            <i className={copied ? "fa-solid fa-check" : "fa-regular fa-copy"} style={{ marginRight: 6 }} />
                            {copied ? "Đã sao chép" : "Sao chép link"}
                        </button>
                    </div>
                </div>
            )}

            {/* Stats */}
            {referral && (
                <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 12, marginTop: 16 }}>
                    <div className="stat-card green">
                        <div className="stat-card__label">Bạn bè đã mời</div>
                        <div className="stat-card__value">{referral.total_referrals || 0}</div>
                        <div className="stat-card__sub">người</div>
                    </div>
                    <div className="stat-card" style={{ borderColor: "rgba(245,158,11,0.15)" }}>
                        <div className="stat-card__label">Credits đã nhận</div>
                        <div className="stat-card__value" style={{ color: "#f59e0b" }}>
                            {referral.total_earned ? Number(referral.total_earned).toLocaleString() : 0}
                        </div>
                        <div className="stat-card__sub">credits</div>
                    </div>
                    <div className="stat-card blue">
                        <div className="stat-card__label">Thưởng đăng ký</div>
                        <div className="stat-card__value">{referral.referral_reward || 800}</div>
                        <div className="stat-card__sub">credits / người</div>
                    </div>
                    <div className="stat-card indigo">
                        <div className="stat-card__label">Hoa hồng mua credit</div>
                        <div className="stat-card__value">{referral.commission_rate || 10}%</div>
                        <div className="stat-card__sub">mỗi lần nạp</div>
                    </div>
                </div>
            )}

            {/* Recent History */}
            {referral?.recent_referrals?.length > 0 && (
                <div className="glass-card" style={{ marginTop: 16 }}>
                    <div className="section-title" style={{ margin: "0 0 12px" }}>Lịch sử nhận thưởng</div>
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Loại</th>
                                <th>Mô tả</th>
                                <th style={{ textAlign: "right" }}>Credits</th>
                                <th>Thời gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            {referral.recent_referrals.map(r => (
                                <tr key={r.id}>
                                    <td>
                                        <span className={`badge-glass ${r.type === "referral" ? "success" : "warning"}`}>
                                            {r.type === "referral" ? "Đăng ký" : "Hoa hồng"}
                                        </span>
                                    </td>
                                    <td style={{ color: "var(--text-primary)" }}>{r.description}</td>
                                    <td style={{ textAlign: "right", fontWeight: 700, color: "#10b981" }}>+{r.amount}</td>
                                    <td>{new Date(r.created_at).toLocaleString("vi-VN")}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
