const notificationApiBase = '/api/notifications';

function getAuthToken() {
    const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
    return token ? `Bearer ${token}` : '';
}

function formatRelativeTime(dateString) {
    if (!dateString) return 'Just now';

    const diffMs = Date.now() - new Date(dateString).getTime();
    const diffMinutes = Math.max(0, Math.floor(diffMs / 60000));

    if (diffMinutes < 1) return 'Just now';
    if (diffMinutes < 60) return `${diffMinutes} minute${diffMinutes === 1 ? '' : 's'} ago`;

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) return `${diffHours} hour${diffHours === 1 ? '' : 's'} ago`;

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays} day${diffDays === 1 ? '' : 's'} ago`;

    return new Date(dateString).toLocaleString();
}

function sortNotifications(notifications) {
    return [...notifications].sort((a, b) => {
        if (Number(a.is_read) === Number(b.is_read)) {
            return new Date(b.created_at) - new Date(a.created_at);
        }

        return Number(a.is_read) - Number(b.is_read);
    });
}

async function fetchNotifications() {
    const endpoint = `${notificationApiBase}?per_page=20`;
    const response = await fetch(endpoint, {
        headers: {
            Authorization: getAuthToken(),
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error('Failed to fetch notifications');
    }

    return response.json();
}

async function markNotificationAsRead(notificationId) {
    const response = await fetch(`${notificationApiBase}/${notificationId}/read`, {
        method: 'PATCH',
        headers: {
            Authorization: getAuthToken(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error('Failed to mark notification as read');
    }

    return response.json();
}

async function markAllNotificationsAsRead() {
    const response = await fetch(`${notificationApiBase}/mark-all-read`, {
        method: 'POST',
        headers: {
            Authorization: getAuthToken(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error('Failed to mark notifications as read');
    }

    return response.json();
}

function renderNotificationList(listEl, notifications) {
    listEl.innerHTML = '';

    if (!notifications.length) {
        listEl.innerHTML = '<div class="notification-empty">No notifications yet.</div>';
        return;
    }

    const sorted = sortNotifications(notifications);

    sorted.forEach((n) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = `notification-item${n.is_read ? ' is-read' : ' is-unread'}`;
        item.setAttribute('aria-label', `${n.title}. ${n.message}`);
        item.innerHTML = `
            <div class="notification-item-header">
                <span class="notification-title">${(n.title || 'Notification').replace(/</g, '&lt;')}</span>
                ${n.is_read ? '' : '<span class="notification-dot" aria-label="Unread notification"></span>'}
            </div>
            <div class="notification-message">${(n.message || '').replace(/</g, '&lt;')}</div>
            <div class="notification-meta">
                <span>${formatRelativeTime(n.created_at)}</span>
                ${n.is_read ? '' : '<button type="button" class="notification-mark-read" data-id="' + n.id + '">Mark as read</button>'}
            </div>
        `;

        item.addEventListener('click', async (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.closest('.notification-mark-read')) {
                event.stopPropagation();
                await markNotificationAsRead(n.id);
                await loadAndRenderNotifications();
                return;
            }

            if (!n.is_read) {
                await markNotificationAsRead(n.id);
            }
            await loadAndRenderNotifications();
        });

        listEl.appendChild(item);
    });
}

async function loadAndRenderNotifications() {
    const listEl = document.querySelector('[data-notification-list]');
    const countEl = document.querySelector('[data-notification-count]');
    const markAllBtn = document.querySelector('[data-mark-all-read]');

    if (!listEl) return;

    listEl.dataset.loading = 'true';

    try {
        const payload = await fetchNotifications();
        const notifications = payload.data?.data || payload.data || [];
        const unread = notifications.filter((n) => !n.is_read).length;

        renderNotificationList(listEl, notifications);

        if (countEl) {
            countEl.textContent = unread;
            countEl.hidden = unread === 0;
        }

        if (markAllBtn) {
            markAllBtn.disabled = unread === 0;
        }
    } catch (error) {
        listEl.innerHTML = '<div class="notification-empty">Unable to load notifications right now.</div>';
    } finally {
        listEl.dataset.loading = 'false';
    }
}

async function configureNotificationUI() {
    const listEl = document.querySelector('[data-notification-list]');
    const markAllBtn = document.querySelector('[data-mark-all-read]');
    if (!listEl) return;

    if (markAllBtn) {
        markAllBtn.addEventListener('click', async () => {
            await markAllNotificationsAsRead();
            await loadAndRenderNotifications();
        });
    }

    await loadAndRenderNotifications();

    if (typeof EventSource !== 'undefined') {
        const source = new EventSource('/api/notifications/stream', {
            withCredentials: true,
        });

        source.addEventListener('notification:update', async () => {
            await loadAndRenderNotifications();
        });

        source.onerror = () => {
            setTimeout(() => {
                source.close();
                configureNotificationUI();
            }, 3000);
        };
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', configureNotificationUI);
} else {
    configureNotificationUI();
}

window.__notificationApi = {
    fetchNotifications,
    markNotificationAsRead,
    markAllNotificationsAsRead,
    loadAndRenderNotifications,
};
