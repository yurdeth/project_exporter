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

        const messages = {
            'light': '☀️ Modo claro activado',
            'dark': '🌙 Modo oscuro activado',
            'system': '💻 Modo sistema activado'
        };

        const notification = document.createElement('div');
        notification.className = 'theme-notification';
        notification.textContent = messages[theme] || messages.system;
        notification.onclick = function() {
            this.classList.add('dismissing');
            setTimeout(() => this.remove(), 300);
        };

        document.body.appendChild(notification);

        // Auto-dismiss after 2 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.classList.add('dismissing');
                setTimeout(() => notification.remove(), 300);
            }
        }, 2000);
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
