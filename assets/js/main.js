/* assets/js/main.js - SOVMS client-side helpers */
(function () {
    // Theme toggle (light/dark) persisted in localStorage
    const root = document.documentElement;
    const saved = localStorage.getItem('sovms-theme');
    if (saved) root.setAttribute('data-theme', saved);

    const btn = document.getElementById('themeToggle');
    if (btn) {
        btn.textContent = root.getAttribute('data-theme') === 'dark' ? '☀️' : '🌙';
        btn.addEventListener('click', function () {
            const cur = root.getAttribute('data-theme');
            const next = cur === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            localStorage.setItem('sovms-theme', next);
            btn.textContent = next === 'dark' ? '☀️' : '🌙';
        });
    }

    // Auto-dismiss flash messages after 5s
    document.querySelectorAll('.flash').forEach(function (el) {
        setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .4s'; }, 5000);
    });

    // CSRF helper for fetch() calls
    window.csrfToken = function () {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    };
})();
