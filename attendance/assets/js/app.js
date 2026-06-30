'use strict';

/* ===== Enable Bootstrap Popover (on element  ====== */
const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]')
const popoverList = [...popoverTriggerList].map(popoverTriggerEl => new bootstrap.Popover(popoverTriggerEl))

/* ==== Enable Bootstrap Alert ====== */
//var alertList = document.querySelectorAll('.alert')
//alertList.forEach(function (alert) {
//  new bootstrap.Alert(alert)
//});

const alertList = document.querySelectorAll('.alert')
const alerts = [...alertList].map(element => new bootstrap.Alert(element))


/* ===== Responsive Sidepanel =====
   NOTE: All sidebar show/hide logic is owned by sidebar-toggle.js.
   app.js does NOT bind click handlers on #sidepanel-toggler or #sidepanel-close
   to avoid race conditions where two listeners toggle the same classes and
   the second one cancels out the first (the source of the intermittent
   "hamburger doesn't open sidebar on some devices" bug).
   We only keep the backdrop click handler here so the dimmed overlay closes
   the sidebar on mobile without conflicting with the toggler. */
const sidePanel = document.getElementById('app-sidepanel');
const sidePanelDrop = document.getElementById('sidepanel-drop');
const sidePanelClose = document.getElementById('sidepanel-close');
const sidePanelToggler = document.getElementById('sidepanel-toggler');

if (sidePanelDrop) {
    sidePanelDrop.addEventListener('click', (e) => {
        if (sidePanelClose) {
            sidePanelClose.click();
        } else if (sidePanel) {
            sidePanel.classList.add('sidepanel-hidden');
            sidePanel.classList.remove('sidepanel-visible');
        }
    });
}



/* ====== Mobile search ======= */
const searchMobileTrigger = document.getElementById('search-mobile-trigger');
const searchMobileClose = document.getElementById('search-mobile-close');
const searchMobileTriggerIcon = document.querySelector('.search-mobile-trigger-icon');
const searchBox = document.getElementById('app-search-box');
const searchInput = searchBox ? searchBox.querySelector('.search-input') : null;

function openMobileSearch() {
    if (!searchBox) return;
    searchBox.classList.add('is-visible');
    if (searchMobileTriggerIcon) {
        searchMobileTriggerIcon.classList.remove('fa-magnifying-glass');
        searchMobileTriggerIcon.classList.add('fa-xmark');
    }
    // Focus the input slightly after the overlay becomes visible so the
    // virtual keyboard doesn't double-trigger the layout reflow.
    setTimeout(() => {
        if (searchInput) searchInput.focus({ preventScroll: true });
    }, 50);
}

function closeMobileSearch() {
    if (!searchBox) return;
    searchBox.classList.remove('is-visible');
    if (searchInput) searchInput.blur();
    if (searchMobileTriggerIcon) {
        searchMobileTriggerIcon.classList.remove('fa-xmark');
        searchMobileTriggerIcon.classList.add('fa-magnifying-glass');
    }
}

if (searchMobileTrigger) {
    searchMobileTrigger.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (searchBox && searchBox.classList.contains('is-visible')) {
            closeMobileSearch();
        } else {
            openMobileSearch();
        }
    });
}

if (searchMobileClose) {
    searchMobileClose.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        closeMobileSearch();
    });
}

// Stop keyboard events inside the search overlay from bubbling into the
// document. Some mobile browsers deliver keypress events to ancestor elements
// (including the sidebar toggle region) which was causing the sidebar to
// open while the user typed in the search input.
if (searchBox) {
    ['keydown', 'keyup', 'keypress', 'input'].forEach((evt) => {
        searchBox.addEventListener(evt, (e) => e.stopPropagation(), true);
    });
}
