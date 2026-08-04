/**
 * AdminCore — Central JS module for Admin Panel
 * Provides AJAX helpers, SweetAlert2 wrappers, form handlers, and utilities.
 */
const AdminCore = (() => {
    'use strict';

    // =========================================================================
    // CSRF Setup
    // =========================================================================
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': csrfToken() }
    });

    // =========================================================================
    // Toast Notifications (SweetAlert2)
    // =========================================================================
    const SwalToast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.onmouseenter = Swal.stopTimer;
            toast.onmouseleave = Swal.resumeTimer;
        }
    });

    /**
     * Show a toast notification.
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} type
     */
    function toast(message, type = 'success') {
        SwalToast.fire({
            icon: type,
            title: message
        });
    }

    // =========================================================================
    // Confirm Dialogs
    // =========================================================================

    /**
     * Show a delete confirmation dialog.
     * @param {string} message
     * @param {Function} onConfirm - called if user confirms
     */
    function confirmDelete(message, onConfirm) {
        Swal.fire({
            title: 'Xác nhận xóa',
            text: message || 'Bạn có chắc muốn xóa mục này?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fas fa-trash me-1"></i> Xóa',
            cancelButtonText: 'Hủy',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed && typeof onConfirm === 'function') {
                onConfirm();
            }
        });
    }

    /**
     * Show a custom confirmation dialog.
     * @param {string} title
     * @param {string} text
     * @param {string} icon - SweetAlert2 icon type
     * @param {Function} onConfirm
     * @param {object} [options] - extra Swal options
     */
    function confirmAction(title, text, icon, onConfirm, options = {}) {
        Swal.fire({
            title: title,
            text: text,
            icon: icon || 'question',
            showCancelButton: true,
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#64748b',
            confirmButtonText: options.confirmText || 'Xác nhận',
            cancelButtonText: options.cancelText || 'Hủy',
            reverseButtons: true,
            ...options
        }).then((result) => {
            if (result.isConfirmed && typeof onConfirm === 'function') {
                onConfirm();
            }
        });
    }

    // =========================================================================
    // AJAX Helper
    // =========================================================================

    /**
     * Perform an AJAX request with automatic CSRF, error handling, and optional loading overlay.
     * @param {string} url
     * @param {string} method - GET, POST, PUT, DELETE, PATCH
     * @param {object|FormData} data
     * @param {object} callbacks - { onSuccess, onError, onComplete }
     * @param {object} [options] - { showLoading: bool, loadingText: string }
     */
    function ajax(url, method, data = {}, callbacks = {}, options = {}) {
        if (options.showLoading !== false) {
            showLoading(options.loadingText);
        }

        const ajaxConfig = {
            url: url,
            type: method.toUpperCase(),
            headers: { 'X-CSRF-TOKEN': csrfToken() },
            success: (response) => {
                if (typeof callbacks.onSuccess === 'function') {
                    callbacks.onSuccess(response);
                }
            },
            error: (xhr) => {
                if (typeof callbacks.onError === 'function') {
                    callbacks.onError(xhr);
                } else {
                    handleAjaxError(xhr);
                }
            },
            complete: () => {
                if (options.showLoading !== false) {
                    hideLoading();
                }
                if (typeof callbacks.onComplete === 'function') {
                    callbacks.onComplete();
                }
            }
        };

        if (data instanceof FormData) {
            ajaxConfig.data = data;
            ajaxConfig.processData = false;
            ajaxConfig.contentType = false;
        } else {
            ajaxConfig.data = data;
        }

        return $.ajax(ajaxConfig);
    }

    /**
     * Default AJAX error handler.
     */
    function handleAjaxError(xhr) {
        let message = 'Đã xảy ra lỗi. Vui lòng thử lại.';

        if (xhr.status === 422 && xhr.responseJSON?.errors) {
            const errors = xhr.responseJSON.errors;
            message = Object.values(errors).flat().join('<br>');
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                html: message
            });
            return;
        }

        if (xhr.status === 419) {
            message = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.';
        } else if (xhr.status === 403) {
            message = 'Bạn không có quyền thực hiện hành động này.';
        } else if (xhr.status === 404) {
            message = 'Không tìm thấy dữ liệu.';
        } else if (xhr.status === 500) {
            message = 'Lỗi hệ thống. Vui lòng thử lại sau.';
        } else if (xhr.responseJSON?.message) {
            message = xhr.responseJSON.message;
        }

        toast(message, 'error');
    }

    // =========================================================================
    // Form Helpers
    // =========================================================================

    /**
     * Submit a form via AJAX.
     * @param {string} formSelector - CSS selector of the form
     * @param {string} url
     * @param {string} method
     * @param {object} callbacks - { onSuccess, onError, onComplete }
     */
    function submitForm(formSelector, url, method, callbacks = {}) {
        const $form = $(formSelector);
        const formData = new FormData($form[0]);

        // Clear previous validation errors
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.invalid-feedback').remove();

        ajax(url, method, formData, {
            onSuccess: (response) => {
                if (typeof callbacks.onSuccess === 'function') {
                    callbacks.onSuccess(response);
                } else {
                    toast(response.message || 'Thành công!', 'success');
                }
            },
            onError: (xhr) => {
                if (xhr.status === 422 && xhr.responseJSON?.errors) {
                    showValidationErrors($form, xhr.responseJSON.errors);
                }
                if (typeof callbacks.onError === 'function') {
                    callbacks.onError(xhr);
                } else if (xhr.status !== 422) {
                    handleAjaxError(xhr);
                }
            },
            onComplete: callbacks.onComplete
        });
    }

    /**
     * Display validation errors on form fields.
     */
    function showValidationErrors($form, errors) {
        Object.entries(errors).forEach(([field, messages]) => {
            const $input = $form.find(`[name="${field}"]`);
            $input.addClass('is-invalid');
            $input.after(`<div class="invalid-feedback">${messages[0]}</div>`);
        });

        // Notify user
        const firstError = Object.values(errors).flat()[0];
        toast(firstError, 'error');
    }

    /**
     * Reset a form: clear values and validation states.
     */
    function resetForm(formSelector) {
        const $form = $(formSelector);
        $form[0].reset();
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.invalid-feedback').remove();
    }

    // =========================================================================
    // Loading Overlay
    // =========================================================================

    function showLoading(text) {
        if ($('#admin-loading-overlay').length) return;
        $('body').append(`
            <div id="admin-loading-overlay" class="admin-loading-overlay">
                <div class="spinner-wrapper">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <span>${text || 'Đang xử lý...'}</span>
                </div>
            </div>
        `);
    }

    function hideLoading() {
        $('#admin-loading-overlay').fadeOut(200, function () {
            $(this).remove();
        });
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /**
     * Format bytes to human readable string.
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    /**
     * Format number with commas.
     */
    function formatNumber(num) {
        return num?.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') || '0';
    }

    // =========================================================================
    // Sidebar Controller
    // =========================================================================

    function initSidebar() {
        // Toggle sidebar on mobile
        $(document).on('click', '.sidebar-toggle', function () {
            $('.sidebar').toggleClass('show');
            $('.sidebar-overlay').toggleClass('show');
        });

        // Close sidebar when clicking overlay
        $(document).on('click', '.sidebar-overlay', function () {
            $('.sidebar').removeClass('show');
            $(this).removeClass('show');
        });

        // Collapsible groups
        $(document).on('click', '.sidebar-group-title', function () {
            const $group = $(this).closest('.sidebar-group');
            const groupId = $group.data('group');
            $group.toggleClass('collapsed');

            // Save state to localStorage
            const collapsed = JSON.parse(localStorage.getItem('sidebarCollapsed') || '{}');
            collapsed[groupId] = $group.hasClass('collapsed');
            localStorage.setItem('sidebarCollapsed', JSON.stringify(collapsed));
        });

        // Restore collapsed state
        const collapsed = JSON.parse(localStorage.getItem('sidebarCollapsed') || '{}');
        Object.entries(collapsed).forEach(([groupId, isCollapsed]) => {
            if (isCollapsed) {
                $(`.sidebar-group[data-group="${groupId}"]`).addClass('collapsed');
            }
        });
    }

    // =========================================================================
    // Backwards Compatibility
    // =========================================================================

    // Keep old showToast working for existing views that haven't migrated
    window.showToast = function (message, type) {
        toast(message, type === 'success' ? 'success' : 'error');
    };
    window.confirmDelete = function (message) {
        // Legacy sync — for views still using if(confirmDelete()) pattern
        return confirm(message || 'Bạn có chắc muốn xóa?');
    };
    window.formatFileSize = formatFileSize;

    // =========================================================================
    // Init
    // =========================================================================
    $(document).ready(function () {
        initSidebar();
    });

    // Public API
    return {
        ajax,
        toast,
        confirmDelete,
        confirmAction,
        submitForm,
        resetForm,
        showLoading,
        hideLoading,
        formatFileSize,
        formatNumber,
        handleAjaxError
    };
})();
