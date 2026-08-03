/**
 * Shared notification toast utility
 * Replaces 6 duplicate notify() implementations across the app
 */
export const notify = (msg, type = "success") => {
    const colors = {
        success: "rgba(16,185,129,.9)",
        error: "rgba(239,68,68,.9)",
        warning: "rgba(245,158,11,.9)",
    };

    const n = document.createElement("div");
    n.style.cssText = `
        position: fixed;
        top: 30px;
        right: 30px;
        background: ${colors[type] || colors.success};
        color: white;
        padding: 16px 24px;
        border-radius: 12px;
        transform: translateX(400px);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 9999;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        font-weight: 500;
        max-width: 350px;
    `;
    n.innerText = msg;
    document.body.appendChild(n);
    setTimeout(() => (n.style.transform = "translateX(0)"), 50);
    setTimeout(() => {
        n.style.transform = "translateX(400px)";
        setTimeout(() => n.remove(), 400);
    }, 3000);
};
