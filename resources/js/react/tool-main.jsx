import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import AccountRoutes from "./routes/AccountRoutes";
import { AuthProvider } from "./auth/AuthProvider";

const el = document.getElementById("root");

if (el) {
    createRoot(el).render(
        <BrowserRouter basename="/">
            <AuthProvider>
                <AccountRoutes />
            </AuthProvider>
        </BrowserRouter>
    );
}
