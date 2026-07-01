// Sidebar toggle (desktop + mobile)
document.addEventListener("DOMContentLoaded", () => {
  const sidepanelTogglerDesktop = document.getElementById("sidepanel-toggler-desktop");
  const sidepanelTogglerMobile = document.getElementById("sidepanel-toggler");
  const sidepanelClose = document.getElementById("sidepanel-close");
  const sidepanelDrop = document.getElementById("sidepanel-drop");
  const appSidepanel = document.getElementById("app-sidepanel");
  const appWrapper = document.querySelector(".app-wrapper");

  if (!appSidepanel) return;

  const SIDEBAR_STATE_KEY = "sidebar_collapsed";
  const MOBILE_TOGGLE_KEY = "sidebar_mobile_user_opened";

  // Track the user's explicit intent on mobile. We DO NOT auto-open the
  // sidebar from resize/layout changes — only an actual click on the
  // hamburger (#sidepanel-toggler) or the close X can flip this flag.
  let isDesktopLayout = window.innerWidth >= 1200;
  let mobileUserOpened =
    localStorage.getItem(MOBILE_TOGGLE_KEY) === "true";

  // Detect virtual-keyboard-driven viewport changes (innerHeight shrinks
  // dramatically while innerWidth stays roughly the same). These must NOT
  // be treated as a breakpoint change because they trigger spurious
  // sidebar state flips.
  let lastInnerWidth = window.innerWidth;
  let lastInnerHeight = window.innerHeight;

  function setDesktopToggleAria(isExpanded) {
    if (sidepanelTogglerDesktop) {
      sidepanelTogglerDesktop.setAttribute(
        "aria-expanded",
        isExpanded ? "true" : "false"
      );
    }
  }

  function setMobileToggleAria(isExpanded) {
    if (sidepanelTogglerMobile) {
      sidepanelTogglerMobile.setAttribute(
        "aria-expanded",
        isExpanded ? "true" : "false"
      );
    }
  }

  function collapseSidebar() {
    appSidepanel.classList.add("collapsed");
    if (window.innerWidth >= 1200) {
      appSidepanel.classList.remove("sidepanel-hidden");
    } else {
      appSidepanel.classList.add("sidepanel-hidden");
    }
    appSidepanel.classList.remove("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.add("sidebar-collapsed");
    }
    setDesktopToggleAria(false);
    setMobileToggleAria(false);
    localStorage.setItem(SIDEBAR_STATE_KEY, "true");
  }

  function expandSidebar() {
    appSidepanel.classList.remove("collapsed");
    appSidepanel.classList.remove("sidepanel-hidden");
    appSidepanel.classList.add("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.remove("sidebar-collapsed");
    }
    setDesktopToggleAria(true);
    setMobileToggleAria(true);
    localStorage.setItem(SIDEBAR_STATE_KEY, "false");
  }

  function openMobileSidebar() {
    appSidepanel.classList.remove("collapsed");
    appSidepanel.classList.remove("sidepanel-hidden");
    appSidepanel.classList.add("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.remove("sidebar-collapsed");
    }
    setMobileToggleAria(true);
    mobileUserOpened = true;
    try { localStorage.setItem(MOBILE_TOGGLE_KEY, "true"); } catch (e) {}
  }

  function closeMobileSidebar() {
    appSidepanel.classList.remove("collapsed");
    appSidepanel.classList.remove("show");
    appSidepanel.classList.add("sidepanel-hidden");
    appSidepanel.classList.remove("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.remove("sidebar-collapsed");
    }
    setMobileToggleAria(false);
    mobileUserOpened = false;
    try { localStorage.setItem(MOBILE_TOGGLE_KEY, "false"); } catch (e) {}
  }

  function toggleSidebar() {
    if (appSidepanel.classList.contains("collapsed")) {
      expandSidebar();
    } else {
      collapseSidebar();
    }
  }

  function initSidebar() {
    if (window.innerWidth >= 1200) {
      const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === "true";
      if (isCollapsed) {
        collapseSidebar();
      } else {
        expandSidebar();
      }
    } else {
      // Mobile: only show the sidebar if the user explicitly opened it
      // during THIS session. Do NOT honor expandSidebar() defaults.
      if (mobileUserOpened) {
        openMobileSidebar();
      } else {
        closeMobileSidebar();
      }
    }
  }

  // ── Desktop toggle button (the icon next to the hamburger) ──────────
  if (sidepanelTogglerDesktop) {
    sidepanelTogglerDesktop.setAttribute("aria-expanded", "true");
    sidepanelTogglerDesktop.setAttribute("role", "button");
    sidepanelTogglerDesktop.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      toggleSidebar();
    });
  }

  // ── Mobile toggle button (hamburger menu) ──────────────────────────
  if (sidepanelTogglerMobile) {
    sidepanelTogglerMobile.setAttribute("aria-expanded", "false");
    sidepanelTogglerMobile.setAttribute("role", "button");
    sidepanelTogglerMobile.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (window.innerWidth < 1200) {
        if (appSidepanel.classList.contains("sidepanel-visible")) {
          closeMobileSidebar();
        } else {
          openMobileSidebar();
        }
      } else {
        toggleSidebar();
      }
    });
  }

  // ── Close button (X icon — inside the sidebar) ─────────────────────
  if (sidepanelClose) {
    sidepanelClose.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (window.innerWidth < 1200) {
        closeMobileSidebar();
      } else {
        toggleSidebar();
      }
    });
  }

  // ── Backdrop click (when sidebar is open on mobile) closes it ──────
  if (sidepanelDrop) {
    sidepanelDrop.addEventListener("click", (e) => {
      e.preventDefault();
      if (window.innerWidth < 1200) {
        closeMobileSidebar();
      } else {
        toggleSidebar();
      }
    });
  }

  // ── Window resize — guard against transient breakpoint flips ──────
  // Mobile browsers (especially iOS Safari and Android Chrome) fire
  // resize events when the soft keyboard opens/closes. innerWidth can
  // transiently jump past 1200px even on a phone. If we naively read
  // localStorage and call expandSidebar() during such a transient flip,
  // the sidebar pops open while the user is filling attendance, with no
  // toggle click. We therefore require:
  //   1. innerWidth crossing the breakpoint for ≥120ms, AND
  //   2. innerHeight changing by more than 80px (which indicates an
  //      orientation / window change, NOT a keyboard event).
  // Otherwise we leave the sidebar alone.
  let resizeTimer = null;
  let pendingBreakpoint = null;

  function applyBreakpoint(nextIsDesktop) {
    isDesktopLayout = nextIsDesktop;
    if (nextIsDesktop) {
      const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === "true";
      if (isCollapsed) collapseSidebar();
      else expandSidebar();
    } else {
      // On mobile, do NOT auto-open. Restore the user's explicit intent
      // from this session, but if they never opened it, keep it closed.
      if (mobileUserOpened) openMobileSidebar();
      else closeMobileSidebar();
    }
  }

  window.addEventListener("resize", () => {
    const newWidth = window.innerWidth;
    const newHeight = window.innerHeight;
    const nextIsDesktop = newWidth >= 1200;
    const heightDelta = Math.abs(newHeight - lastInnerHeight);

    // Always update baselines for the next comparison.
    lastInnerWidth = newWidth;
    lastInnerHeight = newHeight;

    // Only act when the layout breakpoint actually crossed.
    if (nextIsDesktop === isDesktopLayout) {
      return;
    }

    // Soft-keyboard-driven resize: only the height changed meaningfully,
    // not the width crossing. The width "crossing" here is a measurement
    // artefact — ignore it.
    if (heightDelta > 80 && Math.abs(newWidth - lastInnerWidth) < 60) {
      // Looks like keyboard toggling, not a real layout change. Wait.
      return;
    }

    // Debounce: wait 120ms to confirm the layout change is stable before
    // mutating the sidebar. This prevents flashing during orientation
    // animations and pinch-zoom gestures.
    pendingBreakpoint = nextIsDesktop;
    if (resizeTimer) clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (pendingBreakpoint === isDesktopLayout) return;
      applyBreakpoint(pendingBreakpoint);
    }, 120);
  });

  initSidebar();
});