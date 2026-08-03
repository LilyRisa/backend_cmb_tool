import { useEffect, useRef, useState, useCallback } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import api from "../services/api";
import { useAuth } from "../auth/useAuth";
import { notify } from "../utils/notify";


const TURNSTILE_SITE_KEY = window.__TURNSTILE_SITE_KEY || "";

export default function Auth() {
  const navigate = useNavigate();
  const { user, login, refreshUser } = useAuth();
  const bgRef = useRef(null);
  const [activeForm, setActiveForm] = useState("login");
  const [searchParams] = useSearchParams();
  const refCode = searchParams.get("ref") || "";
  const oauthClient = searchParams.get("client") || "";
  // CSRF state do desktop app sinh ra — phải trả nguyên vẹn về deep-link callback,
  // nếu không desktop bản mới sẽ từ chối token (invalid/expired CSRF state).
  const oauthState = searchParams.get("state") || "";

  // OAuth: email verification gate
  const [showVerifyGate, setShowVerifyGate] = useState(false);
  const [resendingVerify, setResendingVerify] = useState(false);
  const [oauthProcessing, setOauthProcessing] = useState(false);
  const [oauthDone, setOauthDone] = useState(false);

  // Auto-switch to register form if referral link
  useEffect(() => {
    if (refCode) setActiveForm("register");
  }, [refCode]);
  const [loadingLogin, setLoadingLogin] = useState(false);
  const [loadingRegister, setLoadingRegister] = useState(false);
  const [loadingForgot, setLoadingForgot] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState("");
  const turnstileRef = useRef(null);
  const turnstileWidgetId = useRef(null);

  // Redirect if logged in (or initiate OAuth if ?client= is present)
  const oauthInitiated = useRef(false);
  useEffect(() => {
    if (!user) return;
    if (oauthClient && !oauthInitiated.current) {
      // Already logged in + OAuth client → skip login form, go straight to OAuth
      oauthInitiated.current = true;
      initiateOAuthRedirect();
      return;
    }
    if (!oauthClient) navigate("/account", { replace: true });
  }, [user, navigate, oauthClient]);

  // Load Turnstile script + render widget
  useEffect(() => {
    if (!TURNSTILE_SITE_KEY) return;

    const scriptId = "cf-turnstile-script";
    if (!document.getElementById(scriptId)) {
      const script = document.createElement("script");
      script.id = scriptId;
      script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onTurnstileLoad";
      script.async = true;
      document.head.appendChild(script);
    }

    window.onTurnstileLoad = () => renderTurnstile();

    // If script already loaded
    if (window.turnstile && turnstileRef.current) renderTurnstile();

    return () => { delete window.onTurnstileLoad; };
  }, []);

  // Re-render when form switches
  useEffect(() => {
    if (window.turnstile && turnstileRef.current) {
      renderTurnstile();
    }
  }, [activeForm]);

  const renderTurnstile = useCallback(() => {
    if (!window.turnstile || !turnstileRef.current || !TURNSTILE_SITE_KEY) return;

    // Reset existing
    if (turnstileWidgetId.current != null) {
      try { window.turnstile.remove(turnstileWidgetId.current); } catch { }
    }

    setTurnstileToken("");
    turnstileWidgetId.current = window.turnstile.render(turnstileRef.current, {
      sitekey: TURNSTILE_SITE_KEY,
      callback: (token) => setTurnstileToken(token),
      "expired-callback": () => setTurnstileToken(""),
      theme: "dark",
    });
  }, []);

  const resetTurnstile = () => {
    setTurnstileToken("");
    if (window.turnstile && turnstileWidgetId.current != null) {
      try { window.turnstile.reset(turnstileWidgetId.current); } catch { }
    }
  };

  // Particle animation
  useEffect(() => {
    const colors = ["#6366f1", "#06b6d4", "#a855f7"];
    function createParticle() {
      if (!bgRef.current) return;
      const p = document.createElement("div");
      const size = Math.random() * 3 + 1;
      Object.assign(p.style, {
        position: "absolute",
        width: `${size}px`, height: `${size}px`,
        background: colors[Math.floor(Math.random() * 3)],
        borderRadius: "50%", opacity: "0",
        left: `${Math.random() * 100}%`,
        animation: `particle-float ${Math.random() * 10 + 10}s linear infinite`,
      });
      bgRef.current.appendChild(p);
      setTimeout(() => p.remove(), 15000);
    }
    const timer = setInterval(createParticle, 1500);
    for (let i = 0; i < 6; i++) setTimeout(createParticle, i * 200);
    return () => clearInterval(timer);
  }, []);

  // OAuth: initiate code exchange after login success
  const initiateOAuthRedirect = async () => {
    setOauthProcessing(true);
    try {
      const res = await api.post("/auth/oauth/authorize", { client_id: oauthClient });
      const code = res.data.code;
      // Redirect to server callback which will redirect to desktop app protocol
      window.location.href = `/oauth/callback?code=${encodeURIComponent(code)}&client=${encodeURIComponent(oauthClient)}`
        + (oauthState ? `&state=${encodeURIComponent(oauthState)}` : "");
      // After a short delay (browser processes protocol redirect), show "done" state
      setTimeout(() => {
        setOauthProcessing(false);
        setOauthDone(true);
      }, 1500);
    } catch (err) {
      if (err.response?.data?.error === "email_not_verified") {
        setShowVerifyGate(true);
      } else {
        const msg = err.response?.data?.message || err.response?.data?.error || "Lỗi xác thực OAuth";
        notify(msg, "error");
      }
      setOauthProcessing(false);
    }
  };

  // OAuth: resend verification email
  const handleResendVerify = async () => {
    setResendingVerify(true);
    try {
      await api.post("/auth/resend-verification");
      notify("Đã gửi lại email xác minh");
    } catch (err) {
      const msg = err.response?.data?.error || "Có lỗi xảy ra";
      notify(msg, "error");
    } finally {
      setResendingVerify(false);
    }
  };

  // OAuth: retry after email verification
  const handleRetryOAuth = async () => {
    setShowVerifyGate(false);
    await initiateOAuthRedirect();
  };

  // Login
  const handleLogin = async (e) => {
    e.preventDefault();
    if (TURNSTILE_SITE_KEY && !turnstileToken) {
      notify("Vui lòng xác thực captcha", "error");
      return;
    }
    const email = e.target.email.value;
    const password = e.target.password.value;
    setLoadingLogin(true);
    try {
      await login(email, password, turnstileToken);
      // If OAuth client, initiate code exchange instead of navigating
      if (oauthClient) {
        notify("Đăng nhập thành công. Đang chuyển hướng...");
        await initiateOAuthRedirect();
        return; // don't setLoadingLogin(false) — page will redirect
      }
      notify("Đăng nhập thành công");
    } catch (err) {
      const msg = err.response?.data?.error || "Sai email hoặc mật khẩu";
      notify(msg, "error");
      resetTurnstile();
    } finally { setLoadingLogin(false); }
  };

  // Register
  const handleRegister = async (e) => {
    e.preventDefault();
    if (TURNSTILE_SITE_KEY && !turnstileToken) {
      notify("Vui lòng xác thực captcha", "error");
      return;
    }
    const name = e.target.name.value;
    const email = e.target.email.value;
    const password = e.target.password.value;
    const confirm = e.target.confirm.value;
    if (password !== confirm) { notify("Mật khẩu không khớp", "error"); return; }
    setLoadingRegister(true);
    try {
      const res = await api.post("/auth/register", {
        name, email, password,
        cf_turnstile_token: turnstileToken,
        ref: refCode || undefined,
      });
      localStorage.setItem("token", res.data.token);
      await refreshUser();
      notify("Đăng ký thành công");
    } catch (err) {
      const msg = err.response?.data?.error
        || err.response?.data?.message
        || (err.response?.data?.errors ? Object.values(err.response.data.errors)[0]?.[0] : "Đăng ký thất bại");
      notify(msg, "error");
      resetTurnstile();
    } finally { setLoadingRegister(false); }
  };

  // Forgot
  const handleForgot = async (e) => {
    e.preventDefault();
    const email = e.target.email.value;
    if (!email) { notify("Vui lòng nhập email", "error"); return; }
    setLoadingForgot(true);
    try {
      await api.post("/auth/forgot-password", { email });
      notify("Đã gửi email khôi phục");
      setTimeout(() => setActiveForm("login"), 2000);
    } catch { notify("Có lỗi xảy ra", "error"); }
    finally { setLoadingForgot(false); }
  };

  // OAuth: already logged in + verified email + client → show clean redirect page
  if (user && oauthClient && user.email_verified) {
    return (
      <div className="auth-page">
        <div className="auth-page__container">
          <div className="auth-page__card" style={{ textAlign: "center" }}>
            <div className="auth-page__logo">
              <div className="auth-page__logo-icon">C</div>
              <h2>CMB Core</h2>
            </div>

            {oauthDone ? (
              <>
                <div style={{ fontSize: 48, marginBottom: 16 }}>✅</div>
                <h3 style={{ marginBottom: 8, color: "#f1f5f9" }}>Đã chuyển hướng</h3>
                <p style={{ color: "#94a3b8", fontSize: 14, lineHeight: 1.6, marginBottom: 20 }}>
                  Ứng dụng CMB Core Marketing đang được mở. Bạn có thể đóng tab này.
                </p>
                <button className="auth-page__submit" onClick={() => window.close()}>
                  Đóng tab
                </button>
              </>
            ) : (
              <>
                <div style={{ marginBottom: 16 }}>
                  <i className="fa-solid fa-spinner spinner" style={{ fontSize: 32, color: "#6366f1" }} />
                </div>
                <p style={{ color: "#94a3b8", fontSize: 14 }}>Đang chuyển hướng tới ứng dụng...</p>
              </>
            )}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="auth-page">
      {/* Particle BG */}
      <div className="auth-page__bg" ref={bgRef}>
        <div style={{
          position: "absolute", width: "120%", height: "120%",
          borderRadius: "50%", opacity: 0.06, top: "10%", left: "-10%",
          background: "radial-gradient(circle, #6366f1 0%, transparent 70%)",
          animation: "float 25s ease-in-out infinite",
        }} />
        <div style={{
          position: "absolute", width: "100%", height: "100%",
          borderRadius: "50%", opacity: 0.04, bottom: "10%", right: "-20%",
          background: "radial-gradient(circle, #06b6d4 0%, transparent 70%)",
          animation: "float 30s ease-in-out infinite reverse",
        }} />
      </div>

      <style>{`
        @keyframes particle-float {
          0% { transform: translateY(100vh); opacity: 0; }
          10% { opacity: 0.6; }
          90% { opacity: 0.3; }
          100% { transform: translateY(-20vh); opacity: 0; }
        }
        @keyframes float {
          0% { transform: translate(0, 0) scale(1); }
          50% { transform: translate(30px, 20px) scale(1.05); }
          100% { transform: translate(0, 0) scale(1); }
        }
      `}</style>

      {/* Card */}
      <div className="auth-page__container">
        <div className="auth-page__card">
          <div className="auth-page__logo">
            <div className="auth-page__logo-icon">C</div>
            <h2>CMB Core</h2>
            <p>
              {activeForm === "login" ? "Đăng nhập vào tài khoản"
                : activeForm === "register" ? "Tạo tài khoản mới"
                  : "Khôi phục mật khẩu"}
            </p>
          </div>

          {/* OAuth client banner */}
          {oauthClient && !showVerifyGate && (
            <div style={{
              padding: "8px 16px", marginBottom: 12, borderRadius: 8,
              background: "rgba(99,102,241,0.15)", border: "1px solid rgba(99,102,241,0.3)",
              fontSize: 13, color: "#a5b4fc", textAlign: "center",
            }}>
              Đăng nhập cho <strong>CMB Core Marketing</strong>
            </div>
          )}

          {/* OAuth: Email verification gate */}
          {showVerifyGate ? (
            <div className="auth-page__form" style={{ textAlign: "center" }}>
              <div style={{ fontSize: 48, marginBottom: 16 }}>📧</div>
              <h3 style={{ marginBottom: 8, color: "#f1f5f9" }}>Xác minh email</h3>
              <p style={{ color: "#94a3b8", fontSize: 14, lineHeight: 1.6, marginBottom: 20 }}>
                Email của bạn chưa được xác minh. Vui lòng kiểm tra hộp thư và xác minh email trước khi đăng nhập vào ứng dụng.
              </p>
              <button
                className="auth-page__submit"
                onClick={handleResendVerify}
                disabled={resendingVerify}
                style={{ marginBottom: 12 }}
              >
                {resendingVerify
                  ? <><i className="fa-solid fa-spinner spinner" /> Đang gửi...</>
                  : "Gửi lại email xác minh"}
              </button>
              <button
                className="auth-page__submit"
                onClick={handleRetryOAuth}
                disabled={oauthProcessing}
                style={{ background: "rgba(99,102,241,0.2)", border: "1px solid rgba(99,102,241,0.4)" }}
              >
                {oauthProcessing
                  ? <><i className="fa-solid fa-spinner spinner" /> Đang kiểm tra...</>
                  : "Đã xác minh — Tiếp tục"}
              </button>
            </div>
          ) : (
            <>
              {/* Tabs */}
              {activeForm !== "forgot" && (
                <div className="auth-page__tabs">
                  <button
                    className={`auth-page__tab ${activeForm === "login" ? "active" : ""}`}
                    onClick={() => setActiveForm("login")}
                  >
                    Đăng nhập
                  </button>
                  <button
                    className={`auth-page__tab ${activeForm === "register" ? "active" : ""}`}
                    onClick={() => setActiveForm("register")}
                  >
                    Đăng ký
                  </button>
                </div>
              )}

              {/* Login */}
              {activeForm === "login" && (
                <form onSubmit={handleLogin} className="auth-page__form">
                  <div className="input-glass">
                    <label>EMAIL</label>
                    <input name="email" type="email" placeholder="name@example.com" required disabled={loadingLogin || oauthProcessing} />
                  </div>
                  <div className="input-glass">
                    <label>MẬT KHẨU</label>
                    <input name="password" type="password" placeholder="••••••••" required disabled={loadingLogin || oauthProcessing} />
                  </div>

                  {/* Turnstile */}
                  {TURNSTILE_SITE_KEY && (
                    <div className="auth-page__turnstile">
                      <div ref={turnstileRef} />
                    </div>
                  )}

                  <button className="auth-page__submit" disabled={loadingLogin || oauthProcessing || (TURNSTILE_SITE_KEY && !turnstileToken)}>
                    {loadingLogin || oauthProcessing
                      ? <><i className="fa-solid fa-spinner spinner" /> {oauthProcessing ? "Đang chuyển hướng..." : "Đang xử lý..."}</>
                      : "Đăng nhập"}
                  </button>
                  <div className="auth-page__footer">
                    <button type="button" onClick={() => setActiveForm("forgot")}>
                      Quên mật khẩu?
                    </button>
                  </div>
                </form>
              )}

              {/* Register */}
              {activeForm === "register" && (
                <form onSubmit={handleRegister} className="auth-page__form">
                  <div className="input-glass">
                    <label>HỌ VÀ TÊN</label>
                    <input name="name" placeholder="Nguyễn Văn A" required disabled={loadingRegister} />
                  </div>
                  <div className="input-glass">
                    <label>EMAIL</label>
                    <input name="email" type="email" placeholder="name@example.com" required disabled={loadingRegister} />
                  </div>
                  <div className="input-glass">
                    <label>MẬT KHẨU</label>
                    <input name="password" type="password" placeholder="Ít nhất 6 ký tự" required disabled={loadingRegister} />
                  </div>
                  <div className="input-glass">
                    <label>XÁC NHẬN MẬT KHẨU</label>
                    <input name="confirm" type="password" placeholder="Nhập lại mật khẩu" required disabled={loadingRegister} />
                  </div>

                  {/* Turnstile */}
                  {TURNSTILE_SITE_KEY && (
                    <div className="auth-page__turnstile">
                      <div ref={turnstileRef} />
                    </div>
                  )}

                  <button className="auth-page__submit" disabled={loadingRegister || (TURNSTILE_SITE_KEY && !turnstileToken)}>
                    {loadingRegister ? <><i className="fa-solid fa-spinner spinner" /> Đang xử lý...</> : "Tạo tài khoản"}
                  </button>
                </form>
              )}

              {/* Forgot */}
              {activeForm === "forgot" && (
                <form onSubmit={handleForgot} className="auth-page__form">
                  <div className="input-glass">
                    <label>EMAIL</label>
                    <input name="email" type="email" placeholder="Nhập email đã đăng ký" required disabled={loadingForgot} />
                  </div>
                  <button className="auth-page__submit" disabled={loadingForgot}>
                    {loadingForgot ? <><i className="fa-solid fa-spinner spinner" /> Đang gửi...</> : "Gửi email khôi phục"}
                  </button>
                  <div className="auth-page__footer">
                    <button type="button" onClick={() => setActiveForm("login")}>
                      Quay lại đăng nhập
                    </button>
                  </div>
                </form>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
