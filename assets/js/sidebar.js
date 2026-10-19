(() => {
    const body = document.body;
    const toggle = document.getElementById('sidebarToggle');
    const backdrop = document.getElementById('sidebarBackdrop');
    const key = 'rumahcare-sidebar-collapsed';
    if (!toggle) return;

    const mobile = () => window.matchMedia('(max-width: 600px)').matches;
    const compactDesktop = () => window.matchMedia('(min-width: 601px) and (max-width: 1000px)').matches;

    function updateLabel(expanded) {
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-label', expanded ? 'Tutup sidebar' : 'Buka sidebar');
        toggle.setAttribute('title', expanded ? 'Tutup sidebar' : 'Buka sidebar');
    }

    function applyWideState(collapsed) {
        body.classList.toggle('sidebar-collapsed', collapsed);
        updateLabel(!collapsed);
    }

    function applyResponsiveState(expanded) {
        body.classList.toggle('sidebar-expanded-responsive', expanded);
        updateLabel(expanded);
    }

    function initializeLayout() {
        body.classList.remove('mobile-sidebar-open', 'sidebar-expanded-responsive');
        if (mobile()) {
            body.classList.remove('sidebar-collapsed');
            updateLabel(false);
        } else if (compactDesktop()) {
            // In split-screen, start with the useful icon rail but allow expansion.
            body.classList.remove('sidebar-collapsed');
            applyResponsiveState(false);
        } else {
            applyWideState(localStorage.getItem(key) === 'true');
        }
    }

    initializeLayout();

    toggle.addEventListener('click', () => {
        if (mobile()) {
            body.classList.toggle('mobile-sidebar-open');
            updateLabel(body.classList.contains('mobile-sidebar-open'));
        } else if (compactDesktop()) {
            const expanded = !body.classList.contains('sidebar-expanded-responsive');
            applyResponsiveState(expanded);
        } else {
            const collapsed = !body.classList.contains('sidebar-collapsed');
            applyWideState(collapsed);
            localStorage.setItem(key, String(collapsed));
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', () => {
            body.classList.remove('mobile-sidebar-open');
            updateLabel(false);
        });
    }

    let previousLayout = `${mobile()}-${compactDesktop()}`;
    window.addEventListener('resize', () => {
        const currentLayout = `${mobile()}-${compactDesktop()}`;
        if (currentLayout !== previousLayout) {
            previousLayout = currentLayout;
            initializeLayout();
        }
    });
})();