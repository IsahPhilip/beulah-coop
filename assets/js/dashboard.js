// assets/js/dashboard.js - General dashboard behaviors

document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh balances via AJAX (optional enhancement)
    console.log('%cBeulah Coop Dashboard initialized', 'color: #28a745; font-weight: bold');

    // Example: You can call createSavingsChart() from member/admin pages
    // createSavingsChart('savingsChart', ['Apr','May','Jun'], [1000, 5000, 15000]);

    const toggle = document.querySelector('.dash-hamburger');
    const sidebar = document.querySelector('.dash-sidebar');
    const overlay = document.querySelector('.dash-overlay');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            document.body.classList.toggle('nav-open');
        });

        document.addEventListener('click', function(e) {
            if (!document.body.classList.contains('nav-open')) return;
            const isInside = sidebar.contains(e.target);
            const isToggle = toggle.contains(e.target);
            if (!isInside && !isToggle) {
                document.body.classList.remove('nav-open');
            }
        });

        if (overlay) {
            overlay.addEventListener('click', function() {
                document.body.classList.remove('nav-open');
            });
        }
    }
});

// Prevent accidental form resubmission on refresh
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
