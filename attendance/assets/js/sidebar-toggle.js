// Sidebar toggle (desktop + mobile)
document.addEventListener("DOMContentLoaded", () => {
  const sidepanelTogglerDesktop = document.getElementById("sidepanel-toggler-desktop");
  const sidepanelTogglerMobile = document.getElementById("sidepanel-toggler");
  const sidepanelClose = document.getElementById("sidepanel-close");
  const appSidepanel = document.getElementById("app-sidepanel");
  const appWrapper = document.querySelector(".app-wrapper");

  if (!appSidepanel) return;

  const SIDEBAR_STATE_KEY = "sidebar_collapsed";
  let isDesktopLayout = window.innerWidth >= 1200;

  if (sidepanelTogglerDesktop) {
    sidepanelTogglerDesktop.setAttribute("aria-expanded", "true");
    sidepanelTogglerDesktop.setAttribute("role", "button");
  }

  if (sidepanelTogglerMobile) {
    sidepanelTogglerMobile.setAttribute("aria-expanded", "false");
    sidepanelTogglerMobile.setAttribute("role", "button");
  }

  function setDesktopToggleAria(isExpanded) {
    if (sidepanelTogglerDesktop) {
      sidepanelTogglerDesktop.setAttribute("aria-expanded", isExpanded ? "true" : "false");
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
    localStorage.setItem(SIDEBAR_STATE_KEY, "false");
  }

  function openMobileSidebar() {
    appSidepanel.classList.remove("collapsed");
    appSidepanel.classList.remove("sidepanel-hidden");
    appSidepanel.classList.add("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.remove("sidebar-collapsed");
    }
    if (sidepanelTogglerMobile) {
      sidepanelTogglerMobile.setAttribute("aria-expanded", "true");
    }
  }

  function closeMobileSidebar() {
    appSidepanel.classList.remove("collapsed");
    appSidepanel.classList.remove("show");
    appSidepanel.classList.add("sidepanel-hidden");
    appSidepanel.classList.remove("sidepanel-visible");
    if (appWrapper) {
      appWrapper.classList.remove("sidebar-collapsed");
    }
    if (sidepanelTogglerMobile) {
      sidepanelTogglerMobile.setAttribute("aria-expanded", "false");
    }
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
      closeMobileSidebar();
    }
  }

  function resetMobileStyles() {
    appSidepanel.classList.remove("sidebar-collapsed");
    if (window.innerWidth < 1200) {
      closeMobileSidebar();
    }
  }

  if (sidepanelTogglerDesktop) {
    sidepanelTogglerDesktop.addEventListener("click", (e) => {
      e.preventDefault();
      toggleSidebar();
    });
  }

  if (sidepanelTogglerMobile) {
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

  if (sidepanelClose) {
    sidepanelClose.addEventListener("click", (e) => {
      e.preventDefault();
      if (window.innerWidth < 1200) {
        closeMobileSidebar();
      } else {
        toggleSidebar();
      }
    });
  }

  window.addEventListener("resize", () => {
    const nextIsDesktopLayout = window.innerWidth >= 1200;

    // Mobile browsers can fire resize when only the address bar height changes.
    // Keep the sidebar state unless the layout crosses the desktop breakpoint.
    if (nextIsDesktopLayout === isDesktopLayout) {
      return;
    }

    isDesktopLayout = nextIsDesktopLayout;

    if (isDesktopLayout) {
      const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === "true";
      if (isCollapsed) {
        collapseSidebar();
      } else {
        expandSidebar();
      }
    } else {
      resetMobileStyles();
    }
  });

  initSidebar();
});
