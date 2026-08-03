import api from "./api";

// ========================
// Credits API
// ========================

export const getCreditsBalance = () => api.get("/tool/credits");

export const getCreditTransactions = (page = 1, pageSize = 30, type = null) => {
    const params = { page, page_size: pageSize };
    if (type) params.type = type;
    return api.get("/tool/credits/transactions", { params });
};

export const getCreditPackages = () => api.get("/tool/credits/packages");

export const createTopup = (packageId) =>
    api.post("/tool/credits/topup", { package_id: packageId });

export const getTopupStatus = (id) => api.get(`/tool/credits/topup/status/${id}`);

// ========================
// Referral API
// ========================

export const getReferralInfo = () => api.get("/tool/credits/referral");

// ========================
// TTS API
// ========================

export const generateTts = (voiceId, data) =>
    api.post(`/tool/tts/${voiceId}`, data);

export const getTtsStatus = (id) => api.get(`/tool/tts/status/${id}`);

export const getTtsHistory = (page = 1, pageSize = 30) =>
    api.get("/tool/tts/history", { params: { page, page_size: pageSize } });

export const deleteTtsHistory = (id) => api.delete(`/tool/tts/history/${id}`);

// ========================
// Models & Voices API
// ========================

export const getModels = (provider = null) => {
    const params = provider ? { provider } : {};
    return api.get("/tool/models", { params });
};

export const getSystemVoices = (filters = {}) =>
    api.get("/tool/voices/system", { params: filters });

export const getClonedVoices = () => api.get("/tool/voices/cloned");

export const cloneVoice = (formData) =>
    api.post("/tool/voices/clone", formData, {
        headers: { "Content-Type": "multipart/form-data" },
    });

export const deleteVoice = (id) => api.delete(`/tool/voices/${id}`);

// ========================
// Subscription API
// ========================

export const getSubscription = () => api.get("/tool/subscription");

export const subscribe = (plan) =>
    api.post("/tool/subscription/subscribe", { plan });
