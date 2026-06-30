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
const searchMobileTrigger = document.querySelector('.search-mobile-trigger');
const searchBox = document.querySelector('.app-search-box');

searchMobileTrigger.addEventListener('click', () => {

	searchBox.classList.toggle('is-visible');
	
	let searchMobileTriggerIcon = document.querySelector('.search-mobile-trigger-icon');
	
	if(searchMobileTriggerIcon.classList.contains('fa-magnifying-glass')) {
		searchMobileTriggerIcon.classList.remove('fa-magnifying-glass');
		searchMobileTriggerIcon.classList.add('fa-xmark');
	} else {
		searchMobileTriggerIcon.classList.remove('fa-xmark');
		searchMobileTriggerIcon.classList.add('fa-magnifying-glass');
	}
	
		
	
});
