import { NavLink, useNavigate } from "react-router-dom";
import { useAuth } from "../auth/useAuth";
import Swal from "sweetalert2";

const NAV_ITEMS = [
    { to: "/account", icon: "fa-table-cells", label: "Dashboard", end: true },
    { to: "/credits", icon: "fa-coins", label: "Credits" },
    { to: "/tts-history", icon: "fa-clock-rotate-left", label: "TTS History" },
    { to: "/topup", icon: "fa-wallet", label: "Nạp credit" },
    { to: "/referral", icon: "fa-gift", label: "Giới thiệu" },
    { to: "/bug-report", icon: "fa-bug", label: "Báo lỗi" },
];

export default function Sidebar({ isOpen, onClose }) {
    const navigate = useNavigate();
    const { user, logout } = useAuth();

    const handleLogout = async () => {
        const result = await Swal.fire({
            title: "Đăng xuất?",
            text: "Bạn có muốn đăng xuất khỏi tài khoản?",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#6366f1",
            cancelButtonColor: "#333",
            confirmButtonText: "Đăng xuất",
            cancelButtonText: "Hủy",
            reverseButtons: true,
            background: "#1a1a24",
            color: "#f0f0f5",
        });

        if (result.isConfirmed) {
            try { await logout(); } catch { }
            navigate("/login", { replace: true });
        }
    };

    const avatarUrl = user?.avatar_url
        || (user?.avatar ? `/storage/${user.avatar}` : "/images/avatar.png");

    return (
        <>
            <div
                className={`sidebar-overlay ${isOpen ? "show" : ""}`}
                onClick={onClose}
            />

            <aside className={`sidebar ${isOpen ? "open" : ""}`}>
                {/* Brand */}
                <div className="sidebar-brand">
                    <div className="sidebar-brand-logo">C</div>
                    <span className="sidebar-brand-text">CMB Core</span>
                </div>

                {/* Navigation */}
                <nav className="sidebar-nav">
                    <div className="nav-section-label">Menu</div>
                    {NAV_ITEMS.map((item) => (
                        <NavLink
                            key={item.to}
                            to={item.to}
                            end={item.end}
                            className={({ isActive }) =>
                                `nav-item ${isActive ? "active" : ""}`
                            }
                            onClick={onClose}
                        >
                            <i className={`fa-solid ${item.icon}`} />
                            <span>{item.label}</span>
                        </NavLink>
                    ))}

                    <div className="nav-section-label" style={{ marginTop: 8 }}>
                        Tài khoản
                    </div>
                    <NavLink
                        to="/account/edit"
                        className={({ isActive }) =>
                            `nav-item ${isActive ? "active" : ""}`
                        }
                        onClick={onClose}
                    >
                        <i className="fa-solid fa-user-gear" />
                        <span>Cài đặt</span>
                    </NavLink>

                    <div className="nav-item" onClick={handleLogout}>
                        <i className="fa-solid fa-right-from-bracket" style={{ color: "#ef4444" }} />
                        <span style={{ color: "#ef4444" }}>Đăng xuất</span>
                    </div>
                </nav>

                {/* Footer – User info */}
                <div className="sidebar-footer">
                    <div
                        className="sidebar-user"
                        onClick={() => { navigate("/account/edit"); onClose?.(); }}
                    >
                        <img
                            src={avatarUrl}
                            alt={user?.name || "User"}
                            className="sidebar-user-avatar"
                        />
                        <div className="sidebar-user-info">
                            <div className="sidebar-user-name">{user?.name || "User"}</div>
                            <div className="sidebar-user-email">{user?.email || ""}</div>
                        </div>
                    </div>
                </div>
            </aside>
        </>
    );
}
