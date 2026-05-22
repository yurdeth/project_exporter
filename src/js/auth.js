// ─── INTERCEPTOR FETCH PARA 401 ───

(function() {
    var originalFetch = window.fetch;
    window.fetch = function() {
        return originalFetch.apply(this, arguments).then(function(response) {
            if (response.status === 401) {
                window.location.reload();
                return Promise.reject(new Error('Sesion expirada'));
            }
            return response;
        });
    };
})();

// ─── LOGIN ───

function handleLogin(event) {
    event.preventDefault();

    var form = event.target;
    var username = form.querySelector('#loginUser').value.trim();
    var password = form.querySelector('#loginPass').value;
    var remember = form.querySelector('#loginRemember').checked;
    var errorDiv = document.getElementById('loginError');

    if (!username || !password) {
        errorDiv.textContent = 'Usuario y contraseña son requeridos';
        errorDiv.style.display = 'block';
        return;
    }

    var formData = new FormData();
    formData.append('username', username);
    formData.append('password', password);
    formData.append('remember', remember ? '1' : '0');

    fetch('?op=login', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            window.location.reload();
        } else if (data.error) {
            errorDiv.textContent = data.error;
            errorDiv.style.display = 'block';
        }
    })
    .catch(function(error) {
        errorDiv.textContent = 'Error al conectar con el servidor';
        errorDiv.style.display = 'block';
    });
}

// ─── LOGOUT ───

function handleLogout() {
    fetch('?op=logout', {
        method: 'POST'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            window.location.reload();
        }
    })
    .catch(function(error) {
        console.error('Error al cerrar sesion:', error);
        window.location.reload();
    });
}

// ─── SESSION CHECK ───

function startSessionCheck() {
    setInterval(function() {
        if (typeof AUTH_ENABLED !== 'undefined' && AUTH_ENABLED && typeof CURRENT_USER !== 'undefined' && CURRENT_USER) {
            fetch('?op=check-session')
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data.valid) {
                        window.location.reload();
                    }
                })
                .catch(function(error) {
                    console.error('Error verificando sesion:', error);
                });
        }
    }, 120000); // 2 minutos
}

// ─── USERS PANEL (ADMIN) ───

function showUsersPanel() {
    var overlay = document.getElementById('usersOverlay');
    if (overlay) {
        overlay.classList.add('active');
        loadUsers();
    }
}

function closeUsersPanel() {
    var overlay = document.getElementById('usersOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        hideInlineForm();
    }
}

function loadUsers() {
    fetch('?op=users')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.users) {
                renderUsersTable(data.users);
            }
        })
        .catch(function(error) {
            console.error('Error cargando usuarios:', error);
        });
}

function renderUsersTable(users) {
    var tbody = document.getElementById('usersTableBody');
    if (!tbody) return;

    tbody.innerHTML = '';

    users.forEach(function(user) {
        var isCurrentUser = CURRENT_USER && user.id === CURRENT_USER.id;

        var tr = document.createElement('tr');
        if (isCurrentUser) {
            tr.classList.add('is-current-user');
        }

        var usernameCell = '<span class="username">' + escHtml(user.username) + '</span>';
        if (isCurrentUser) {
            usernameCell += ' <span class="you-badge">Tú</span>';
        }

        var actions = '';
        if (user.role !== 'admin' || isCurrentUser) {
            actions += '<button class="btn-sm btn-edit" onclick="handleEditUser(' + user.id + ', \'' + escAttr(user.username) + '\', \'' + user.role + '\', ' + isCurrentUser + ')" title="Editar usuario"><span class="icon"><svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg></span></button>';
        }
        if (user.role !== 'admin') {
            actions += '<button class="btn-sm btn-danger" onclick="handleDeleteUser(' + user.id + ', \'' + escAttr(user.username) + '\')" title="Eliminar usuario"><span class="icon"><svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg></span></button>';
        }
        if (actions === '') {
            actions = '<span style="color: var(--color-text-tertiary); font-size: 0.75rem;">—</span>';
        }

        tr.innerHTML =
            '<td>' + usernameCell + '</td>' +
            '<td><span class="role-badge ' + user.role + '">' + (user.role === 'admin' ? 'Admin' : 'User') + '</span></td>' +
            '<td>' + new Date(user.created_at).toLocaleDateString('es-ES') + '</td>' +
            '<td class="actions-cell">' + actions + '</td>';
        tbody.appendChild(tr);
    });
}

function showCreateUserForm() {
    hideInlineForm();

    var tbody = document.getElementById('usersTableBody');
    if (!tbody) return;

    var formRow = document.createElement('tr');
    formRow.id = 'inlineFormRow';
    formRow.innerHTML =
        '<td colspan="4">' +
            '<div class="inline-form">' +
                '<div class="inline-form-row">' +
                    '<div class="inline-form-group">' +
                        '<label>Usuario</label>' +
                        '<input type="text" id="newUsername" placeholder="usuario">' +
                    '</div>' +
                    '<div class="inline-form-group">' +
                        '<label>Contraseña</label>' +
                        '<input type="password" id="newPassword" placeholder="mínimo 6 caracteres">' +
                    '</div>' +
                    // Solo admin puede crear users, no necesita seleccionar rol
                    // '<div class="inline-form-group" style="flex: 0 0 auto;">' +
                    //     '<label>Rol</label>' +
                    //     '<select id="newRole" disabled>' +
                    //         '<option value="user" selected>User</option>' +
                    //     '</select>' +
                    // '</div>' +
                '</div>' +
                '<div class="inline-form-row">' +
                    '<button type="button" class="btn-cancel" onclick="hideInlineForm()">Cancelar</button>' +
                    '<button type="button" class="btn-save" onclick="handleCreateUser()">Guardar</button>' +
                '</div>' +
            '</div>' +
        '</td>';

    tbody.appendChild(formRow);
    document.getElementById('newUsername').focus();
}

function handleCreateUser() {
    var username = document.getElementById('newUsername').value.trim();
    var password = document.getElementById('newPassword').value;
    var role = 'user';

    if (!username || !password) {
        alert('Usuario y contraseña son requeridos');
        return;
    }

    if (password.length < 6) {
        alert('La contraseña debe tener al menos 6 caracteres');
        return;
    }

    var formData = new FormData();
    formData.append('username', username);
    formData.append('password', password);
    formData.append('role', role);

    fetch('?op=user-create', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            loadUsers();
        } else if (data.error) {
            alert('Error: ' + data.error);
        }
    })
    .catch(function(error) {
        alert('Error al crear usuario');
        console.error(error);
    });
}

function handleEditUser(id, currentUsername, currentRole, isOwnProfile) {
    hideInlineForm();

    var tbody = document.getElementById('usersTableBody');
    if (!tbody) return;

    var canChangeRole = !isOwnProfile && isAdmin();

    var formRow = document.createElement('tr');
    formRow.id = 'inlineFormRow';

    var formContent =
        '<td colspan="4">' +
            '<div class="inline-form">' +
                '<div class="inline-form-row">' +
                    '<div class="inline-form-group">' +
                        '<label>Usuario</label>' +
                        '<input type="text" id="editUsername" value="' + escAttr(currentUsername) + '">' +
                    '</div>' +
                    '<div class="inline-form-group">' +
                        '<label>Nueva contraseña (opcional)</label>' +
                        '<input type="password" id="editPassword" placeholder="Dejar vacío para no cambiar">' +
                    '</div>';

    if (canChangeRole) {
        formContent +=
                    '<div class="inline-form-group">' +
                        '<label>Rol</label>' +
                        '<select id="editRole">' +
                            '<option value="admin" ' + (currentRole === 'admin' ? 'selected' : '') + '>Admin</option>' +
                            '<option value="user" ' + (currentRole === 'user' ? 'selected' : '') + '>User</option>' +
                        '</select>' +
                    '</div>';
    } else {
        formContent += '<input type="hidden" id="editRole" value="' + escAttr(currentRole) + '">';
    }

    formContent +=
                '</div>' +
                '<div class="inline-form-row">' +
                    '<button type="button" class="btn-cancel" onclick="hideInlineForm()">Cancelar</button>' +
                    '<button type="button" class="btn-save" onclick="handleUpdateUser(' + id + ', ' + isOwnProfile + ')">Guardar</button>' +
                '</div>' +
            '</div>' +
        '</td>';

    formRow.innerHTML = formContent;

    tbody.appendChild(formRow);
    document.getElementById('editUsername').focus();
}

function handleUpdateUser(id, isOwnProfile) {
    var username = document.getElementById('editUsername').value.trim();
    var password = document.getElementById('editPassword').value;
    var roleSelect = document.getElementById('editRole');
    var role = roleSelect ? roleSelect.value : null;

    if (!username) {
        alert('El nombre de usuario es requerido');
        return;
    }

    if (password !== '' && password.length < 6) {
        alert('La contraseña debe tener al menos 6 caracteres');
        return;
    }

    var formData = new FormData();
    formData.append('id', id);
    formData.append('username', username);
    if (password !== '') {
        formData.append('password', password);
    }
    if (role !== null) {
        formData.append('role', role);
    }

    fetch('?op=user-update', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Si editó su propio username, recargar la página para actualizar badges
            if (isOwnProfile) {
                window.location.reload();
            } else {
                loadUsers();
            }
        } else if (data.error) {
            alert('Error: ' + data.error);
        }
    })
    .catch(function(error) {
        alert('Error al actualizar usuario');
        console.error(error);
    });
}

function handleDeleteUser(id, username) {
    if (!confirm('¿Eliminar al usuario "' + username + '"?')) {
        return;
    }

    var formData = new FormData();
    formData.append('id', id);

    fetch('?op=user-delete', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            loadUsers();
        } else if (data.error) {
            alert('Error: ' + data.error);
        }
    })
    .catch(function(error) {
        alert('Error al eliminar usuario');
        console.error(error);
    });
}

function hideInlineForm() {
    var formRow = document.getElementById('inlineFormRow');
    if (formRow) {
        formRow.remove();
    }
}

// ─── CHANGE PASSWORD ───

function showChangePasswordForm() {
    var overlay = document.getElementById('passwordOverlay');
    if (overlay) {
        overlay.classList.add('active');
        document.getElementById('currentPassword').focus();
    }
}

function closeChangePasswordForm() {
    var overlay = document.getElementById('passwordOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        document.getElementById('passwordError').style.display = 'none';
        document.getElementById('changePasswordForm').reset();
    }
}

function handleChangePassword(event) {
    event.preventDefault();

    var current = document.getElementById('currentPassword').value;
    var newPass = document.getElementById('newPassword').value;
    var confirm = document.getElementById('confirmPassword').value;
    var errorDiv = document.getElementById('passwordError');

    if (!current || !newPass || !confirm) {
        errorDiv.textContent = 'Todos los campos son requeridos';
        errorDiv.style.display = 'block';
        return;
    }

    if (newPass.length < 6) {
        errorDiv.textContent = 'La nueva contraseña debe tener al menos 6 caracteres';
        errorDiv.style.display = 'block';
        return;
    }

    if (newPass !== confirm) {
        errorDiv.textContent = 'La confirmación no coincide con la nueva contraseña';
        errorDiv.style.display = 'block';
        return;
    }

    var formData = new FormData();
    formData.append('current_password', current);
    formData.append('new_password', newPass);

    fetch('?op=change-password', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            closeChangePasswordForm();
            alert('Contraseña cambiada exitosamente');
        } else if (data.error) {
            errorDiv.textContent = data.error;
            errorDiv.style.display = 'block';
        }
    })
    .catch(function(error) {
        errorDiv.textContent = 'Error al cambiar contraseña';
        errorDiv.style.display = 'block';
        console.error(error);
    });
}

// ─── INIT ───

if (typeof AUTH_ENABLED !== 'undefined' && AUTH_ENABLED) {
    startSessionCheck();
}
