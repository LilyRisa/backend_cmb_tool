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
