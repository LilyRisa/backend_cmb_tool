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
