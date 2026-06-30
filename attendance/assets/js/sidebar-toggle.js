// Sidebar toggle (desktop + mobile)
document.addEventListener("DOMContentLoaded", () => {
  const sidepanelTogglerDesktop = document.getElementById("sidepanel-toggler-desktop");
  const sidepanelTogglerMobile = document.getElementById("sidepanel-toggler");
  const sidepanelClose = document.getElementById("sidepanel-close");
  const appSidepanel = document.getElementById("app-sidepanel");
  const appWrapper = document.querySelector(".app-wrapper");

  if (!appSidepanel) return;

  const SIDEBAR_STATE_KEY = "sidebar_collapsed";

  // Set initial aria-expanded on the desktop toggle
  if (sidepanelTogglerDesktop) {
    sidepanelTogglerDesktop.setAttribute("aria-expanded", "true");
    sidepanelTogglerDesktop.setAttribute("role", "button");
  }

  function setToggleAria(isExpanded) {
    if (sidepanelTogglerDesktop) {
      sidepanelTogglerDesktop.setAttribute(
        "aria-expanded",
        isExpanded ? "true" : "false"
      );
    }
  }

  function collapseSidebar() {
    appSidepanel.classList.add("collapsed");
    // On desktop we don't use sidepanel-hidden (that pushes off-screen)
    // Only remove the off-screen positioning for desktop
    if (window.innerWidth >= 1200) {
      appSidepanel.classList.remove("sidepanel-hidden");
    } else {
      appSidepanel.classList.add("sidepanel-hidden");
    }
    appSidepanel.classList.remove("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.add("sidebar-collapsed");
    }
    setToggleAria(false);
    localStorage.setItem(SIDEBAR_STATE_KEY, "true");
  }

  function expandSidebar() {
    appSidepanel.classList.remove("collapsed");
    appSidepanel.classList.remove("sidepanel-hidden");
    appSidepanel.classList.add("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.remove("sidebar-collapsed");
    }
    setToggleAria(true);
    localStorage.setItem(SIDEBAR_STATE_KEY, "false");
  }

  function toggleSidebar() {
    if (appSidepanel.classList.contains("collapsed")) {
      expandSidebar();
    } else {
      collapseSidebar();
    }
  }

  function initSidebar() {
    // Only apply collapsed state on desktop (≥1200px)
    if (window.innerWidth >= 1200) {
      const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === "true";
      if (isCollapsed) {
        collapseSidebar();
      } else {
        expandSidebar();
      }
    } else {
      // Mobile/tablet: keep sidebar hidden by default (slide-in pattern)
      appSidepanel.classList.remove("collapsed");
      appSidepanel.classList.add("sidepanel-hidden");
      appSidepanel.classList.remove("sidepanel-visible");
      if (appWrapper) {
        appWrapper.classList.remove("sidebar-collapsed");
      }
      setToggleAria(true);
    }
  }

  function resetMobileStyles() {
    appSidepanel.classList.remove("collapsed");
    appSidepanel.classList.remove("sidebar-collapsed");
    if (appWrapper) {
      appWrapper.classList.remove("sidebar-collapsed");
    }
    // On mobile, hide sidebar by default
    if (window.innerWidth < 1200) {
      appSidepanel.classList.add("sidepanel-hidden");
      appSidepanel.classList.remove("sidepanel-visible");
    }
  }

  // Desktop toggle button (the icon next to the hamburger)
  if (sidepanelTogglerDesktop) {
    sidepanelTogglerDesktop.addEventListener("click", (e) => {
      e.preventDefault();
      toggleSidebar();
    });
  }

  // Mobile toggle button (hamburger menu)
  if (sidepanelTogglerMobile) {
    sidepanelTogglerMobile.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (window.innerWidth < 1200) {
        // On mobile: explicitly open or close based on current state.
        // We DO NOT toggle the "show" class — that's a Bootstrap collapse
        // class with no CSS rule for sidebar visibility, and toggling it
        // alongside the visible/hidden classes caused the sidebar to fail
        // to open on some devices when other handlers ran in sequence.
        if (appSidepanel.classList.contains("sidepanel-visible")) {
          appSidepanel.classList.remove("sidepanel-visible");
          appSidepanel.classList.add("sidepanel-hidden");
        } else {
          appSidepanel.classList.remove("sidepanel-hidden");
          appSidepanel.classList.add("sidepanel-visible");
        }
      } else {
        toggleSidebar();
      }
    });
  }

  // Close button (X icon — mobile only)
  if (sidepanelClose) {
    sidepanelClose.addEventListener("click", (e) => {
      e.preventDefault();
      if (window.innerWidth < 1200) {
        appSidepanel.classList.remove("show");
        appSidepanel.classList.add("sidepanel-hidden");
        appSidepanel.classList.remove("sidepanel-visible");
      } else {
        toggleSidebar();
      }
    });
  }

  // Handle window resize — re-apply correct layout for the breakpoint
  window.addEventListener("resize", () => {
    if (window.innerWidth < 1200) {
      // Switch to mobile layout
      resetMobileStyles();
    } else {
      // Switch to desktop layout — restore saved state
      const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === "true";
      if (isCollapsed) {
        collapseSidebar();
      } else {
        expandSidebar();
      }
    }
  });

  // Initialize on page load
  initSidebar();
});