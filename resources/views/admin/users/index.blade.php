@extends('admin.layout')

@section('title', 'User Management')
@section('page-title', 'User Management')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Users']]])
@endsection

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-users"></i> Users List</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetUserForm()">
            <i class="fas fa-plus"></i> Add New User
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="usersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Avatar</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Gói</th>
                        <th>Credits</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                    <tr id="user-{{ $user->id }}">
                        <td>{{ $user->id }}</td>
                        <td>
                            @if($user->avatar)
                            <img src="{{ asset('storage/' . $user->avatar) }}" alt="Avatar" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                            @else
                            <div style="width:36px;height:36px;border-radius:50%;background:var(--admin-primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:14px;">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </div>
                            @endif
                        </td>
                        <td>
                            <span style="font-weight:500;">{{ $user->name }}</span>
                            @if($user->is_admin)
                            <i class="fas fa-shield-alt text-warning ms-1" title="Admin"></i>
                            @endif
                        </td>
                        <td><small>{{ $user->email }}</small></td>
                        <td>
                            @if($user->package_type === 'free')
                            <span class="badge bg-secondary">Free</span>
                            @elseif($user->isPremium())
                            <span class="badge bg-warning text-dark">{{ ucfirst($user->package_type) }}</span>
                            @else
                            <span class="badge bg-secondary" title="package_type vẫn là {{ $user->package_type }} nhưng đã hết hạn (package_expires_at đã qua)">{{ ucfirst($user->package_type) }} (Hết hạn)</span>
                            @endif
                        </td>
                        <td>
                            <strong>{{ number_format(($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0)) }}</strong>
                            <br><small class="text-muted">M: {{ number_format($user->monthly_credits ?? 0) }} | P: {{ number_format($user->purchased_credits ?? 0) }}</small>
                        </td>
                        <td><small>{{ $user->created_at->format('Y-m-d H:i') }}</small></td>
                        <td class="action-buttons">
                            <a href="{{ route('admin.tool-stats.user', $user->id) }}" class="btn btn-sm btn-outline-info" title="Chi tiết / Thống kê">
                                <i class="fas fa-chart-line"></i>
                            </a>
                            <button class="btn btn-sm btn-outline-primary" onclick="editUser({{ $user->id }})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser({{ $user->id }}, '{{ addslashes($user->name) }}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalLabel">Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm">
                <input type="hidden" id="userId" name="user_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="userName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="userName" name="name" required>
                        <div class="invalid-feedback" id="nameError"></div>
                    </div>
                    <div class="mb-3">
                        <label for="userEmail" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="userEmail" name="email" required>
                        <div class="invalid-feedback" id="emailError"></div>
                    </div>
                    <div class="mb-3">
                        <label for="userPassword" class="form-label">Password <span class="text-danger" id="passwordRequired">*</span></label>
                        <input type="password" class="form-control" id="userPassword" name="password">
                        <small class="text-muted">Leave blank to keep current password (when editing)</small>
                        <div class="invalid-feedback" id="passwordError"></div>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="userIsAdmin" name="is_admin">
                        <label class="form-check-label" for="userIsAdmin">
                            <i class="fas fa-shield-alt text-warning me-1"></i><strong>Grant Admin Privileges</strong>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    let isEditing = false;

    function resetUserForm() {
        isEditing = false;
        $('#userForm')[0].reset();
        $('#userId').val('');
        $('#userModalLabel').text('Add New User');
        $('#passwordRequired').show();
        $('#userPassword').prop('required', true);
        $('.form-control').removeClass('is-invalid');
        $('.invalid-feedback').text('');
    }

    function editUser(id) {
        isEditing = true;
        $('#userModalLabel').text('Edit User');
        $('#passwordRequired').hide();
        $('#userPassword').prop('required', false);

        AdminCore.ajax(`/admin/users/${id}`, 'GET', {}, {
            onSuccess: function(response) {
                if (response.success) {
                    const user = response.user;
                    $('#userId').val(user.id);
                    $('#userName').val(user.name);
                    $('#userEmail').val(user.email);
                    $('#userIsAdmin').prop('checked', user.is_admin);
                    $('#userModal').modal('show');
                }
            }
        });
    }

    function deleteUser(id, name) {
        AdminCore.confirmDelete(`Bạn có chắc muốn xóa user <strong>${name}</strong>? Toàn bộ dữ liệu liên quan (credit, lịch sử TTS...) sẽ bị xóa.`, function() {
            AdminCore.ajax(`/admin/users/${id}`, 'DELETE', {}, {
                onSuccess: function(response) {
                    if (response.success) {
                        $(`#user-${id}`).fadeOut(300, function() {
                            $(this).remove();
                        });
                        AdminCore.toast(response.message, 'success');
                    }
                }
            });
        });
    }

    $('#userForm').on('submit', function(e) {
        e.preventDefault();
        $('.form-control').removeClass('is-invalid');
        $('.invalid-feedback').text('');

        const userId = $('#userId').val();
        const url = userId ? `/admin/users/${userId}` : '/admin/users';
        const method = userId ? 'PUT' : 'POST';

        const formData = {
            name: $('#userName').val(),
            email: $('#userEmail').val(),
            password: $('#userPassword').val(),
            is_admin: $('#userIsAdmin').is(':checked') ? 1 : 0
        };

        AdminCore.ajax(url, method, formData, {
            onSuccess: function(response) {
                if (response.success) {
                    $('#userModal').modal('hide');
                    AdminCore.toast(response.message, 'success');
                    setTimeout(() => location.reload(), 800);
                }
            },
            onError: function(xhr) {
                if (xhr.status === 422 && xhr.responseJSON?.errors) {
                    const errors = xhr.responseJSON.errors;
                    Object.keys(errors).forEach(field => {
                        const el = $(`#user${field.charAt(0).toUpperCase() + field.slice(1)}`);
                        if (el.length) {
                            el.addClass('is-invalid');
                            el.siblings('.invalid-feedback').text(errors[field][0]);
                        }
                    });
                }
            }
        });
    });
</script>
@endpush
