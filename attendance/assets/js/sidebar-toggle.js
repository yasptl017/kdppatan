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
  }

  // ── Mobile toggle button (hamburger menu) ──────────────────────────
  if (sidepanelTogglerMobile) {
    sidepanelTogglerMobile.setAttribute("aria-expanded", "false");
    sidepanelTogglerMobile.setAttribute("role", "button");
  }

  // ── Close button (X icon — inside the sidebar) ─────────────────────
  // Initial ARIA is set; click handler is attached later with scroll-guard.

  // ── Window resize — guard against spurious resize events on mobile ─
  // Strict policy: on mobile, the sidebar MUST NOT auto-open or auto-close
  // in response to ANY resize event. Mobile browsers (iOS Safari, Android
  // Chrome) fire `resize` during scroll-bounce, rubber-band overscroll,
  // soft-keyboard show/hide, address bar collapse, and pinch-zoom. Each of
  // those events can briefly push innerWidth across the 1200px breakpoint,
  // and reacting to it would open the sidebar while the user is just
  // scrolling the page.
  //
  // The sidebar visibility on mobile is governed EXCLUSIVELY by user
  // gestures (hamburger click, close button click, backdrop tap). Resize
  // is allowed only to update `isDesktopLayout` for CSS purposes and to
  // re-apply a confirmed desktop layout transition (with a long debounce
  // and stable-width check, see below). On mobile, this listener is a
  // no-op.
  let resizeStableSinceMs = 0;
  let pendingDesktop = null;
  let resizeDebounce = null;

  // Track consecutive resize events at a stable width. Only when innerWidth
  // remains across the breakpoint for ≥400ms do we treat it as a real
  // transition. Scroll-bounce produces a single transient read followed
  // by a return to the original width, so it never accumulates.
  window.addEventListener("resize", () => {
    const newWidth = window.innerWidth;
    const newHeight = window.innerHeight;
    const heightDelta = Math.abs(newHeight - lastInnerHeight);
    const widthDelta = Math.abs(newWidth - lastInnerWidth);
    lastInnerWidth = newWidth;
    lastInnerHeight = newHeight;
    const now = Date.now();

    const nextIsDesktop = newWidth >= 1200;

    // No layout change at all — nothing to do.
    if (nextIsDesktop === isDesktopLayout) {
      resizeStableSinceMs = 0;
      pendingDesktop = null;
      return;
    }

    // We crossed the breakpoint. Track stability: is this width the same
    // as the last event? If yes, accumulate; if no, reset.
    if (pendingDesktop === nextIsDesktop) {
      if (resizeStableSinceMs === 0) resizeStableSinceMs = now;
      const stableFor = now - resizeStableSinceMs;
      if (stableFor < 400) {
        // Not stable yet — wait. Do NOT mutate the sidebar.
        if (resizeDebounce) clearTimeout(resizeDebounce);
        resizeDebounce = setTimeout(() => {
          // After the wait, re-check: are we still at the new breakpoint?
          if (window.innerWidth >= 1200 === pendingDesktop) {
            isDesktopLayout = pendingDesktop;
            if (pendingDesktop) {
              const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === "true";
              if (isCollapsed) collapseSidebar();
              else expandSidebar();
            } else {
              // Mobile: keep current state, do NOT mutate.
              // (Mobile sidebar is governed by user clicks only.)
            }
          }
          resizeStableSinceMs = 0;
          pendingDesktop = null;
        }, 400);
        return;
      }
    } else {
      // New transient direction — start stability countdown fresh.
      pendingDesktop = nextIsDesktop;
      resizeStableSinceMs = now;
      if (resizeDebounce) clearTimeout(resizeDebounce);
      resizeDebounce = setTimeout(() => {
        if (window.innerWidth >= 1200 === pendingDesktop) {
          isDesktopLayout = pendingDesktop;
          if (pendingDesktop) {
            const isCollapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === "true";
            if (isCollapsed) collapseSidebar();
            else expandSidebar();
          }
        }
        resizeStableSinceMs = 0;
        pendingDesktop = null;
      }, 400);
      return;
    }

    // Suppress unused-var lint warnings (heightDelta/widthDelta are useful
    // for future diagnostics but not for this check).
    void heightDelta;
    void widthDelta;
  });

  // ── Scroll-gesture guard ──────────────────────────────────────────
  // iOS Safari and Android Chrome sometimes synthesise a click on the
  // hamburger when a touch-scroll ENDS with the finger near the toggle
  // area. This is a well-known platform quirk. To suppress it, we mark
  // a short window after every `scrollend`/`touchend` during which any
  // click on the hamburger or close button is ignored.
  let lastScrollEnd = 0;
  const SCROLL_GUARD_MS = 350;

  function noteScrollEnd() {
    lastScrollEnd = Date.now();
  }

  // scrollend is supported on modern mobile browsers.
  window.addEventListener("scrollend", noteScrollEnd, { passive: true });
  // Fallback for browsers without scrollend: detect touch end on document.
  document.addEventListener(
    "touchend",
    (e) => {
      // Only count it as a scroll if the touch was a small movement.
      // If the touch is followed immediately by a click on the toggle,
      // the click will be dropped because lastScrollEnd is recent.
      // We cannot reliably distinguish a tap from a scroll-end from a
      // single touchend alone, so we only flag the timestamp — the click
      // guard below will reject any click that happens within the window.
      if (!e.touches || e.touches.length === 0) {
        noteScrollEnd();
      }
    },
    { passive: true }
  );

  // Wrap the existing click handlers on the toggle / close buttons so
  // they no-op when invoked within the scroll-guard window.
  function guardClick(handler) {
    return (e) => {
      if (Date.now() - lastScrollEnd < SCROLL_GUARD_MS) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }
      handler(e);
    };
  }

  // Re-bind click handlers with the guard wrapper.
  if (sidepanelTogglerMobile) {
    sidepanelTogglerMobile.removeEventListener("click", sidepanelTogglerMobile.__origHandler || (() => {}));
    const origHandler = (e) => {
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
    };
    sidepanelTogglerMobile.__origHandler = origHandler;
    sidepanelTogglerMobile.addEventListener("click", guardClick(origHandler));
  }
  if (sidepanelClose) {
    const origHandler = (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (window.innerWidth < 1200) {
        closeMobileSidebar();
      } else {
        toggleSidebar();
      }
    };
    sidepanelClose.addEventListener("click", guardClick(origHandler));
  }

  // ── Safety net: MutationObserver catches any rogue `sidepanel-visible`
  // class added by other code on mobile and forces it off unless the user
  // has actually opened the sidebar via the hamburger in this session.
  if (typeof MutationObserver !== "undefined") {
    const mo = new MutationObserver(() => {
      if (window.innerWidth < 1200 && !mobileUserOpened) {
        if (appSidepanel.classList.contains("sidepanel-visible")) {
          appSidepanel.classList.remove("sidepanel-visible");
          appSidepanel.classList.add("sidepanel-hidden");
        }
      }
    });
    mo.observe(appSidepanel, { attributes: true, attributeFilter: ["class"] });
  }

  initSidebar();
});