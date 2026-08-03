import { useState, useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../auth/useAuth";
import api from "../services/api";
import { notify } from "../utils/notify";

export default function EditAccountPage() {
    const navigate = useNavigate();
    const { user, refreshUser } = useAuth();
    const [formData, setFormData] = useState({ name: "", avatar: null });
    const [avatarPreview, setAvatarPreview] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const fileInputRef = useRef(null);

    useEffect(() => {
        if (user) {
            setFormData({ name: user.name || "", avatar: null });
            setAvatarPreview(
                user.avatar_url || (user.avatar ? (user.avatar.startsWith("http") ? user.avatar : `/storage/${user.avatar}`) : "/images/avatar.png")
            );
        }
    }, [user]);

    const handleAvatarChange = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (!file.type.startsWith("image/")) { notify("Chỉ chấp nhận hình ảnh", "error"); return; }
        if (file.size > 2 * 1024 * 1024) { notify("Tối đa 2MB", "error"); return; }
        const reader = new FileReader();
        reader.onloadend = () => setAvatarPreview(reader.result);
        reader.readAsDataURL(file);
        setFormData(prev => ({ ...prev, avatar: file }));
    };

    const handleSubmit = async (e) => {
        e?.preventDefault();
        if (!formData.name.trim()) { notify("Tên không được để trống", "error"); return; }
        setIsLoading(true);
        try {
            const data = new FormData();
            data.append("name", formData.name);
            if (formData.avatar) data.append("avatar", formData.avatar);
            await api.post("/account/profile", data);
            notify("Cập nhật thành công");
            if (fileInputRef.current) fileInputRef.current.value = "";
            await refreshUser();
            setTimeout(() => navigate("/account"), 800);
        } catch (error) {
            const msg = error.response?.data?.message || error.response?.data?.errors
                ? Object.values(error.response.data.errors)[0]?.[0]
                : "Cập nhật thất bại";
            notify(msg, "error");
        } finally { setIsLoading(false); }
    };

    if (!user) return (
        <div className="empty-state"><div className="loading-spinner" /><div className="loading-text">Đang tải...</div></div>
    );

    return (
        <div className="animate-in bento">
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-end", marginBottom: 24 }}>
                <div>
                    <h1 className="bento__title">Cài đặt tài khoản</h1>
                    <p style={{ color: "var(--text-muted)", fontSize: 14, margin: 0 }}>
                        Quản lý thông tin cá nhân và bảo mật
                    </p>
                </div>
                <button className="btn-icon" onClick={() => navigate(-1)}>
                    <i className="fa-solid fa-xmark" />
                </button>
            </div>

            <div className="bento__grid">
                {/* Avatar */}
                <div className="bento__card bento__card--profile">
                    <div className="bento__avatar-wrap" style={{ cursor: "pointer" }} onClick={() => fileInputRef.current?.click()}>
                        <img src={avatarPreview || "/images/avatar.png"} alt="Avatar" />
                        <label className="bento__upload-badge" onClick={e => e.stopPropagation()}>
                            <i className="fa-solid fa-camera" />
                        </label>
                        <input type="file" ref={fileInputRef} hidden accept="image/*" onChange={handleAvatarChange} />
                    </div>
                    <h3 style={{ fontWeight: 700, marginTop: 12, marginBottom: 4, color: "var(--text-primary)" }}>{user.name || "User"}</h3>
                    <span className="badge-glass info">{user.email}</span>
                </div>

                {/* Name */}
                <div className="bento__card bento__card--wide">
                    <div className="section-title">Thông tin cá nhân</div>
                    <form onSubmit={handleSubmit} id="profile-form">
                        <div className="input-glass">
                            <label>HỌ VÀ TÊN</label>
                            <input type="text" value={formData.name} onChange={e => setFormData(p => ({ ...p, name: e.target.value }))}
                                placeholder="Nhập tên" disabled={isLoading} required />
                        </div>
                    </form>
                </div>

                {/* Contact */}
                <div className="bento__card bento__card--wide" style={{ opacity: 0.5 }}>
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                        <div className="section-title" style={{ margin: 0 }}>Liên hệ</div>
                        <span className="badge-glass info">Sắp ra mắt</span>
                    </div>
                    <div className="input-glass" style={{ marginTop: 12 }}>
                        <label>SỐ ĐIỆN THOẠI</label>
                        <input type="text" placeholder="Thêm số điện thoại..." disabled />
                    </div>
                    <div className="input-glass" style={{ marginTop: 8 }}>
                        <label>EMAIL DỰ PHÒNG</label>
                        <input type="email" placeholder="Thêm email..." disabled />
                    </div>
                </div>

                {/* Security */}
                <div className="bento__card">
                    <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 12 }}>
                        <div style={{ width: 32, height: 32, borderRadius: 8, background: "rgba(99,102,241,0.15)", display: "flex", alignItems: "center", justifyContent: "center", color: "#6366f1" }}>
                            <i className="fa-solid fa-shield-halved" />
                        </div>
                        <div className="section-title" style={{ margin: 0 }}>Bảo mật</div>
                    </div>
                    <p style={{ fontSize: 13, color: "var(--text-muted)" }}>Xác thực 2 yếu tố đang bật</p>
                    <button className="btn-apple" style={{ width: "100%" }}>Thay đổi mật khẩu</button>
                </div>
            </div>

            {/* Action Bar */}
            <div className="bento__action-bar">
                <button className="btn-glass-secondary" onClick={() => navigate("/account")} disabled={isLoading}>Hủy bỏ</button>
                <button className="btn-primary-glow" onClick={handleSubmit} disabled={isLoading} type="submit" form="profile-form">
                    {isLoading ? <><i className="fa-solid fa-spinner spinner" /> Đang lưu...</> : "Lưu thay đổi"}
                </button>
            </div>
        </div>
    );
}
