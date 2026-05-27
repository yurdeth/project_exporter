// ─── THEME SYSTEM ───
(function() {
    'use strict';

    const THEME_KEY = 'project-exporter-theme';
    const THEMES = ['light', 'dark', 'system'];

    function getSavedTheme() {
        const saved = localStorage.getItem(THEME_KEY);
        return (saved && THEMES.includes(saved)) ? saved : 'system';
    }

    function getSystemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        const effective = theme === 'system' ? getSystemTheme() : theme;
        document.documentElement.setAttribute('data-theme', effective);
    }

    function updateThemeOptions(activeTheme) {
        const options = document.querySelectorAll('.theme-option');
        options.forEach(opt => {
            const theme = opt.getAttribute('data-theme');
            opt.classList.toggle('active', theme === activeTheme);
        });
    }

    function setTheme(theme) {
        localStorage.setItem(THEME_KEY, theme);
        applyTheme(theme);
        updateThemeOptions(theme);
        showThemeNotification(theme);
    }

    function showThemeNotification(theme) {
        // Remove existing notification if any
        const existing = document.querySelector('.theme-notification');
        if (existing) {
            existing.classList.add('dismissing');
            setTimeout(() => existing.remove(), 300);
        }

        const icons = {
            'light': '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
            'dark': '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
            'system': '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>'
        };

        const messages = {
            'light': 'Modo claro activado',
            'dark': 'Modo oscuro activado',
            'system': 'Modo sistema activado'
        };

        const notification = document.createElement('div');
        notification.className = 'theme-notification';
        notification.innerHTML = `${icons[theme] || icons.system} ${messages[theme] || messages.system}`;
        notification.onclick = function() {
            this.classList.add('dismissing');
            setTimeout(() => this.remove(), 300);
        };

        document.body.appendChild(notification);

        // Auto-dismiss after 1 second
        setTimeout(() => {
            if (notification.parentNode) {
                notification.classList.add('dismissing');
                setTimeout(() => notification.remove(), 280);
            }
        }, 1000);
    }

    // Initialize
    const saved = getSavedTheme();
    applyTheme(saved);
    updateThemeOptions(saved);

    // Listen for system theme changes
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (getSavedTheme() === 'system') applyTheme('system');
        });
    }

    // Expose globally
    window.setTheme = setTheme;
    window.getSavedTheme = getSavedTheme;
    window.applyTheme = applyTheme;

})();
