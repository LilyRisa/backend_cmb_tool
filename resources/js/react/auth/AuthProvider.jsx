import { createContext, useContext, useEffect, useState } from "react";
import api from "../services/api";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  // chạy 1 lần khi app load
  useEffect(() => {
    const token = localStorage.getItem("token");
    if (!token) {
      setLoading(false);
      return;
    }

    api.get("/me")
      .then(res => setUser(res.data))
      .catch(() => {
        localStorage.removeItem("token");
        setUser(null);
      })
      .finally(() => setLoading(false));
  }, []);

  const login = async (email, password, cfTurnstileToken = "") => {
    const payload = { email, password };
    if (cfTurnstileToken) payload.cf_turnstile_token = cfTurnstileToken;

    const res = await api.post("/auth/login", payload);
    localStorage.setItem("token", res.data.token);

    // Nếu API trả về user data, dùng nó; nếu không, fetch từ /me
    if (res.data.user) {
      setUser(res.data.user);
    } else {
      // Fetch user data từ API
      const userRes = await api.get("/me");
      setUser(userRes.data);
    }
  };

  const refreshUser = async () => {
    const token = localStorage.getItem("token");
    if (!token) {
      setUser(null);
      return;
    }
    try {
      const res = await api.get("/me");
      setUser(res.data);
    } catch {
      localStorage.removeItem("token");
      setUser(null);
    }
  };

  const logout = async () => {
    await api.post("/logout");
    localStorage.removeItem("token");
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export default AuthContext;