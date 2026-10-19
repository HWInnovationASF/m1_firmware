<?php
$session_usertype = isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : '';
$is_superadmin = $session_usertype === 'superadmin';
$is_admin = $session_usertype === 'admin';
?>

<?php if (!$is_superadmin && !$is_admin): ?>
<div class="mx-user-mgmt-wrapper" style="border:1px solid #ddd;border-radius:8px;background:#FFF;text-align:center;">
    <div style="font-size:18px;font-weight:700;color:#dc3545;margin-bottom:5px;">Access denied</div>
    <p class="mb-0">You do not have permission to view this page.</p>
</div>
<?php else: ?>

<div class="mx-user-mgmt-wrapper" style="border:1px solid #ddd;border-radius:8px;background:#FFF;">
    <div style="font-size:22px;font-weight:700;text-align:center;margin-bottom:5px;">User Management</div>
    <p class="text-center" style="color:#6b7a99;margin-bottom:20px;">
        <?php if ($is_superadmin): ?>
            Signed in as superadmin — you can create and delete any account.
        <?php else: ?>
            Signed in as admin — you can create and delete customer accounts.
        <?php endif; ?>
    </p>

    <div style="font-size:16px;font-weight:700;margin-bottom:10px;">Create an account</div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
        <i class="fa-solid fa-user-plus"></i>
        Create account
    </button>

    <div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form id="createUserForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Create an account</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-lg-6">
                                <label for="new_username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" class="form-control" id="new_username" placeholder="Username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_]+">
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label for="new_type" class="form-label">Role</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user-shield"></i></span>
                                    <select class="form-select" id="new_type" <?php echo $is_admin ? 'disabled' : ''; ?>>
                                        <?php if ($is_superadmin): ?>
                                            <option value="customer">Customer</option>
                                            <option value="admin">Admin</option>
                                            <option value="superadmin">Superadmin</option>
                                        <?php else: ?>
                                            <option value="customer" selected>Customer</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label for="new_password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" class="form-control" id="new_password" placeholder="Password" required minlength="4">
                                    <span class="input-group-text toggle-password" data-target="new_password" role="button"><i class="mdi mdi-eye-outline"></i></span>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label for="new_password_confirm" class="form-label">Confirm password</label>
                                <div class="input-group position-relative">
                                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" class="form-control" id="new_password_confirm" placeholder="Confirm password" required minlength="4" style="padding-right:28px;">
                                    <span id="new_password_match_icon" style="position:absolute;right:55px;top:50%;transform:translateY(-50%);pointer-events:none;"></span>
                                    <span class="input-group-text toggle-password" data-target="new_password_confirm" role="button"><i class="mdi mdi-eye-outline"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="cancelCreateUser()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-user-plus"></i>
                            Create account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form id="editUserForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit account</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="edit_username">
                        <div class="row g-2 mb-2">
                            <div class="col-12">
                                <label class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" class="form-control" id="edit_username_display" disabled>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label for="edit_type" class="form-label">Role</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user-shield"></i></span>
                                    <select class="form-select" id="edit_type"></select>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6"></div>
                            <?php if ($is_superadmin): ?>
                            <div class="col-12"><div class="up-box"><div><b>Display Access</b><small>เลือกหน้าที่ User สามารถเปิดดูได้</small></div><div class="up-grid" id="upDisplays"><label><input type="checkbox" value="dashboard"> Dashboard</label><label><input type="checkbox" value="alldata"> All Readings</label><label><input type="checkbox" value="solar-performance"> Solar Monitoring</label><label><input type="checkbox" value="energy"> Energy Diagram</label><label><input type="checkbox" value="device"> Manage Devices</label><label><input type="checkbox" value="network"> Network</label><label><input type="checkbox" value="communication"> Communication</label><label><input type="checkbox" value="about-system"> About System</label></div></div></div>
                            <div class="col-12"><div class="up-box"><div><b>Function Access</b><small>เลือกความสามารถที่ User ใช้งานได้</small></div><div class="up-grid" id="upFunctions"><label><input type="checkbox" value="report"> Report</label><label><input type="checkbox" value="automation"> Automation</label><label><input type="checkbox" value="manual_command"> Manual Command</label><label><input type="checkbox" value="device_config"> Device Config</label><label><input type="checkbox" value="export"> Export</label></div></div></div>
                            <?php endif; ?>
                            <div class="col-12 col-lg-6">
                                <label for="edit_password" class="form-label">New password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" class="form-control" id="edit_password" placeholder="Leave blank to keep current" minlength="4">
                                    <span class="input-group-text toggle-password" data-target="edit_password" role="button"><i class="mdi mdi-eye-outline"></i></span>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label for="edit_password_confirm" class="form-label">Confirm new password</label>
                                <div class="input-group position-relative">
                                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" class="form-control" id="edit_password_confirm" placeholder="Confirm new password" minlength="4" style="padding-right:28px;">
                                    <span id="edit_password_match_icon" style="position:absolute;right:55px;top:50%;transform:translateY(-50%);pointer-events:none;"></span>
                                    <span class="input-group-text toggle-password" data-target="edit_password_confirm" role="button"><i class="mdi mdi-eye-outline"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="cancelEditUser()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <hr>

    <div style="font-size:16px;font-weight:700;margin-bottom:10px;">Existing accounts</div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Access</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <tr><td colspan="4" class="text-center text-muted">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>
<style>.up-box{padding:11px;border:1px solid #dce6ef;border-radius:10px;background:#f8fbfd}.up-box b,.up-box small{display:block}.up-box b{font-size:.78rem;color:#294b67}.up-box small{margin-top:2px;color:#7b8d9d;font-size:.62rem}.up-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:9px}.up-grid label{display:flex;align-items:center;gap:6px;padding:7px 8px;border:1px solid #d8e3ec;border-radius:8px;background:#fff;color:#526b82;font-size:.66rem;cursor:pointer}.up-grid input{accent-color:#1769d2}.up-access-summary{display:flex;max-width:390px;flex-wrap:wrap;gap:4px}.up-access-badge{display:inline-flex;align-items:center;gap:3px;padding:3px 6px;border-radius:6px;font-size:.57rem;font-weight:700;text-transform:capitalize;white-space:nowrap}.up-access-badge.display{background:#e8f2fd;color:#1769d2}.up-access-badge.function{background:#edf8f2;color:#168958}.up-access-badge.all{background:#e9f8f0;color:#147c4d}.up-access-badge.none{background:#f5eded;color:#a05656}.up-access-default{align-self:center;padding:2px 4px;color:#8b99a6;font-size:.52rem}@media(max-width:600px){.up-grid{grid-template-columns:1fr 1fr}.up-access-summary{min-width:190px}}</style>

<script>
    $('.toggle-password').click(function(){
        $(this).find('i').toggleClass('mdi-eye-outline mdi-eye-off-outline');
        const input = document.getElementById($(this).data('target'));
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    function updateEditPasswordMatchIcon(){
        const password = $('#edit_password').val();
        const confirm = $('#edit_password_confirm').val();
        const icon = $('#edit_password_match_icon');

        if(confirm === ''){
            icon.html('');
            return;
        }
        if(password === confirm){
            icon.html('<i class="fa-solid fa-check text-success"></i>');
        } else {
            icon.html('<i class="fa-solid fa-xmark text-danger"></i>');
        }
    }
    $('#edit_password, #edit_password_confirm').on('input', updateEditPasswordMatchIcon);

    function updateNewPasswordMatchIcon(){
        const password = $('#new_password').val();
        const confirm = $('#new_password_confirm').val();
        const icon = $('#new_password_match_icon');

        if(confirm === ''){
            icon.html('');
            return;
        }
        if(password === confirm){
            icon.html('<i class="fa-solid fa-check text-success"></i>');
        } else {
            icon.html('<i class="fa-solid fa-xmark text-danger"></i>');
        }
    }
    $('#new_password, #new_password_confirm').on('input', updateNewPasswordMatchIcon);

    function loadUsers(){
        $.ajax({
            type: 'GET',
            url: './mx_fn_api.php?action=user_list',
            dataType: 'json',
            success: function(users){
                const tbody = $('#userTableBody');
                tbody.empty();
                if(!users.length){
                    tbody.append('<tr><td colspan="4" class="text-center text-muted">No accounts to show</td></tr>');
                    return;
                }
                users.forEach(function(u){
                    const row = $('<tr></tr>');
                    row.append($('<td></td>').text(u.username));
                    row.append($('<td></td>').append($('<span class="badge bg-primary-subtle text-primary"></span>').text(u.type)));
                    const access=$('<td class="up-access-summary"></td>'),permissions=u.permissions||{},displays=permissions.displays||[],functions=permissions.functions||[];
                    if(displays.includes('*')&&functions.includes('*')){
                        access.append('<span class="up-access-badge all"><i class="fa-solid fa-unlock"></i> Full access</span>');
                    }else{
                        if(displays.includes('*'))access.append('<span class="up-access-badge display"><i class="fa-solid fa-display"></i> All displays</span>');
                        displays.filter(name=>name!=='*').forEach(name=>access.append($('<span class="up-access-badge display"><i class="fa-solid fa-display"></i></span>').append(document.createTextNode(' '+String(name).replaceAll('-',' ')))));
                        functions.forEach(name=>access.append($('<span class="up-access-badge function"><i class="fa-solid fa-bolt"></i></span>').append(document.createTextNode(' '+String(name).replaceAll('_',' ')))));
                        if(!displays.length&&!functions.length)access.append('<span class="up-access-badge none"><i class="fa-solid fa-lock"></i> No access</span>');
                        if(!u.permissions_custom)access.append('<small class="up-access-default">Default</small>');
                    }
                    row.append(access);
                    const actionCell = $('<td class="text-end"></td>');
                    const editBtn = $('<button type="button" class="btn btn-sm btn-outline-primary me-1"><i class="fa-solid fa-pen"></i></button>');
                    editBtn.click(function(){ openEditUser(u.username, u.type, u.is_self); });
                    actionCell.append(editBtn);

                    const delBtn = $('<button type="button" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>');
                    if(u.is_self){
                        delBtn.prop('disabled', true).attr('title', 'You cannot delete your own account');
                    } else {
                        delBtn.click(function(){ deleteUser(u.username); });
                    }
                    actionCell.append(delBtn);
                    row.append(actionCell);
                    tbody.append(row);
                });
            },
            error: function(){
                $('#userTableBody').html('<tr><td colspan="4" class="text-center text-danger">Failed to load accounts</td></tr>');
            }
        });
    }

    function deleteUser(username){
        Swal.fire({
            title: `Delete account "${username}"?`,
            showCancelButton: true,
            confirmButtonText: "Delete",
            confirmButtonColor: "#dc3545",
        }).then((result) => {
            if(!result.isConfirmed) return;
            $.ajax({
                type: 'POST',
                url: './mx_fn_api.php?action=user_delete',
                data: { username: username },
                success: function(response){
                    Swal.fire({ icon: "success", title: "Deleted", showConfirmButton: false, timer: 1200 });
                    loadUsers();
                },
                error: function(xhr){
                    Swal.fire({ icon: "error", title: "Failed", text: xhr.responseText || "Could not delete account" });
                }
            });
        });
    }

    // Closing via the X button, backdrop click, or Esc leaves the typed values untouched
    // (the modal is just hidden, the form DOM is never reset) so the user's input survives.
    // Cancel explicitly clears the form before closing.
    function cancelCreateUser(){
        document.getElementById('createUserForm').reset();
        updateNewPasswordMatchIcon();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('createUserModal')).hide();
    }

    $('#createUserForm').on('submit', function(e){
        e.preventDefault();

        const username = $('#new_username').val().trim();
        const password = $('#new_password').val();
        const passwordConfirm = $('#new_password_confirm').val();
        const type = $('#new_type').val();

        if(password !== passwordConfirm){
            Swal.fire({ icon: "error", title: "Passwords do not match" });
            return;
        }

        $.ajax({
            type: 'POST',
            url: './mx_fn_api.php?action=user_create',
            data: { username: username, password: password, password_confirm: passwordConfirm, type: type },
            success: function(response){
                bootstrap.Modal.getOrCreateInstance(document.getElementById('createUserModal')).hide();
                $('#createUserForm')[0].reset();
                updateNewPasswordMatchIcon();
                Swal.fire({ icon: "success", title: "Account created", showConfirmButton: false, timer: 1200 });
                loadUsers();
            },
            error: function(xhr){
                Swal.fire({ icon: "error", title: "Failed", text: xhr.responseText || "Could not create account" });
            }
        });
    });

    const isSuperadmin = <?php echo $is_superadmin ? 'true' : 'false'; ?>;

    function openEditUser(username, type, isSelf){
        $('#editUserForm')[0].reset();
        updateEditPasswordMatchIcon();
        $('#edit_username').val(username);
        $('#edit_username_display').val(username);
        <?php if ($is_superadmin): ?>
        document.querySelectorAll('#upDisplays input,#upFunctions input').forEach(input=>input.checked=false);
        fetch('./user_permission_api.php?username='+encodeURIComponent(username)).then(r=>r.json()).then(p=>{document.querySelectorAll('#upDisplays input').forEach(input=>{input.checked=type==='admin'||type==='superadmin'||(p.displays||[]).includes(input.value);input.disabled=type==='admin'||type==='superadmin'});document.querySelectorAll('#upFunctions input').forEach(input=>input.checked=type==='superadmin'||(p.functions||[]).includes(input.value))});
        <?php endif; ?>

        const roleSelect = $('#edit_type');
        roleSelect.empty();
        if(isSuperadmin){
            ['customer', 'admin', 'superadmin'].forEach(function(t){
                roleSelect.append($('<option></option>').attr('value', t).text(t.charAt(0).toUpperCase() + t.slice(1)));
            });
            roleSelect.val(type);
            // Prevent locking yourself out by changing your own role.
            roleSelect.prop('disabled', isSelf);
        } else {
            // Admins can view/edit admin accounts too now, but never change any role via this form.
            roleSelect.append($('<option></option>').attr('value', type).text(type.charAt(0).toUpperCase() + type.slice(1)));
            roleSelect.prop('disabled', true);
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
    }

    function cancelEditUser(){
        document.getElementById('editUserForm').reset();
        updateEditPasswordMatchIcon();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).hide();
    }

    $('#editUserForm').on('submit', function(e){
        e.preventDefault();

        const username = $('#edit_username').val();
        const password = $('#edit_password').val();
        const passwordConfirm = $('#edit_password_confirm').val();
        const type = $('#edit_type').prop('disabled') ? '' : $('#edit_type').val();

        if(password !== passwordConfirm){
            Swal.fire({ icon: "error", title: "Passwords do not match" });
            return;
        }
        if(type === '' && password === '' && !isSuperadmin){
            Swal.fire({ icon: "info", title: "Nothing to update" });
            return;
        }
        const savePermissions = () => {
            if(!isSuperadmin) return Promise.resolve();
            const permissions={displays:[...document.querySelectorAll('#upDisplays input:checked')].map(x=>x.value),functions:[...document.querySelectorAll('#upFunctions input:checked')].map(x=>x.value)};
            return fetch('./user_permission_api.php?username='+encodeURIComponent(username),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(permissions)}).then(async r=>{const d=await r.json();if(!r.ok)throw Error(d.error||'Permission save failed')});
        };
        if(type === '' && password === '' && isSuperadmin){savePermissions().then(()=>{bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).hide();Swal.fire({icon:'success',title:'Permissions updated',showConfirmButton:false,timer:1200});loadUsers()}).catch(e=>Swal.fire({icon:'error',title:'Failed',text:e.message}));return;}

        $.ajax({
            type: 'POST',
            url: './mx_fn_api.php?action=user_edit',
            data: { username: username, type: type, password: password },
            success: function(response){
                savePermissions();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).hide();
                $('#editUserForm')[0].reset();
                updateEditPasswordMatchIcon();
                Swal.fire({ icon: "success", title: "Account updated", showConfirmButton: false, timer: 1200 });
                loadUsers();
            },
            error: function(xhr){
                Swal.fire({ icon: "error", title: "Failed", text: xhr.responseText || "Could not update account" });
            }
        });
    });

    loadUsers();
</script>

<style>
    .mx-user-mgmt-wrapper{
        max-width:800px;
        margin-left:auto;
        margin-right:auto;
        padding:15px;
    }
    @media (min-width: 768px){
        .mx-user-mgmt-wrapper{
            padding:25px 40px;
        }
    }
    @media (min-width: 992px){
        .mx-user-mgmt-wrapper{
            max-width:1100px;
        }
    }
</style>

<?php endif; ?>
