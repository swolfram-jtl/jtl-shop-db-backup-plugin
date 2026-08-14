{* Shared CSS for all jtl_dbbackup_tool tabs. Uses the official JTL Brand
   Design System 2026 palette (Dark Blue / Orange / Tech Blue / Light Blue /
   Fresh Green / Red), scoped entirely under .dbbackup-page so it re-skins
   only this plugin's own tabs and never leaks into the rest of the shop
   admin. Base surfaces (card backgrounds, body text) still come from the
   shop's own Bootstrap classes for light/dark-theme safety — only accent
   colors (buttons, badges, borders, icons) are overridden, since those read
   fine on both a light and a dark admin theme by design (that's what an
   "accent" color is for). *}
<style>
.dbbackup-page {
    --jtl-dark-blue: #0B1B45;
    --jtl-sand: #EEEEE7;
    --jtl-tech-blue: #2722F8;
    --jtl-light-blue: #89D2FF;
    --jtl-orange: #FB581F;
    --jtl-green: #00DB6A;
    --jtl-red: #DB2000;
    font-size: .925rem;
}
.dbbackup-page h5, .dbbackup-page h4 { font-weight: 700; color: var(--jtl-dark-blue); }

.dbbackup-eyebrow {
    text-transform: uppercase;
    letter-spacing: .05em;
    font-size: .7rem;
    font-weight: 700;
    color: var(--jtl-dark-blue);
    opacity: .65;
}

/* Brand-colored buttons: Orange is JTL's dedicated CTA color */
.dbbackup-page .btn-primary {
    background-color: var(--jtl-orange);
    border-color: var(--jtl-orange);
    font-weight: 600;
}
.dbbackup-page .btn-primary:hover,
.dbbackup-page .btn-primary:focus {
    background-color: #e04a15;
    border-color: #e04a15;
}
.dbbackup-page .btn-outline-primary {
    color: var(--jtl-dark-blue);
    border-color: var(--jtl-dark-blue);
}
.dbbackup-page .btn-outline-primary:hover {
    background-color: var(--jtl-dark-blue);
    border-color: var(--jtl-dark-blue);
}
.dbbackup-page .btn-link { color: var(--jtl-tech-blue); }
.dbbackup-page .text-primary { color: var(--jtl-tech-blue) !important; }
.dbbackup-page .border-primary { border-color: var(--jtl-tech-blue) !important; }
.dbbackup-page .bg-primary { background-color: var(--jtl-dark-blue) !important; }

/* Status badges: Fresh Green / Red per brand system */
.dbbackup-page .badge-success { background-color: var(--jtl-green); color: #fff; }
.dbbackup-page .badge-danger { background-color: var(--jtl-red); color: #fff; }

/* KPI tiles */
.dbbackup-tile {
    border-radius: .7rem;
    border: none;
    transition: box-shadow .15s ease, transform .15s ease;
}
.dbbackup-tile:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1.1rem rgba(11,27,69,.1); }
.dbbackup-tile--placeholder { opacity: .5; filter: saturate(.4); }
.dbbackup-kpi-value {
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--jtl-dark-blue);
    line-height: 1.15;
}
.dbbackup-kpi-unit { font-size: .95rem; font-weight: 500; color: #7788AA; }
.dbbackup-icon-circle {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}
.dbbackup-icon-circle--blue { background: var(--jtl-dark-blue); color: #fff; }
.dbbackup-icon-circle--tech { background: var(--jtl-tech-blue); color: #fff; }
.dbbackup-icon-circle--light { background: var(--jtl-light-blue); color: var(--jtl-dark-blue); }
.dbbackup-icon-circle--sand { background: var(--jtl-sand); color: var(--jtl-dark-blue); }
.dbbackup-icon-circle--orange { background: var(--jtl-orange); color: #fff; }

/* Preset cards */
.dbbackup-preset-card { border-radius: .7rem; border-color: rgba(11,27,69,.12) !important; transition: box-shadow .15s ease, border-color .15s ease; }
.dbbackup-preset-card:hover { box-shadow: 0 .4rem 1rem rgba(11,27,69,.08); border-color: var(--jtl-tech-blue) !important; }
.dbbackup-preset-card--full { border-width: 2px !important; border-color: var(--jtl-orange) !important; }

/* Option row info icon */
.dbbackup-info-icon { cursor: help; color: var(--jtl-tech-blue) !important; }

/* Empty-state lightbox overlay */
.dbbackup-widgets-wrap { position: relative; }
.dbbackup-overlay-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(11,27,69,.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    border-radius: .7rem;
    padding: 1rem;
}
.dbbackup-overlay-card {
    max-width: 28rem;
    width: 100%;
    box-shadow: 0 1rem 3rem rgba(11,27,69,.35);
    border-radius: .8rem;
    border: none;
}

/* Bell-style failure indicator (replaces a permanent red banner) */
.dbbackup-bell-toggle {
    list-style: none;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    cursor: pointer;
    user-select: none;
    color: var(--jtl-red);
}
.dbbackup-bell-toggle::-webkit-details-marker { display: none; }
.dbbackup-bell-toggle::marker { content: ""; }
.dbbackup-bell-ring {
    animation: dbbackup-ring 1.6s ease-in-out infinite;
    transform-origin: top center;
}
@keyframes dbbackup-ring {
    0%, 100% { transform: rotate(0deg); }
    10% { transform: rotate(12deg); }
    20% { transform: rotate(-10deg); }
    30% { transform: rotate(6deg); }
    40% { transform: rotate(0deg); }
}
@media (prefers-reduced-motion: reduce) {
    .dbbackup-bell-ring { animation: none; }
}

.dbbackup-recent-row:not(:last-child) { border-bottom: 1px solid rgba(11,27,69,.08); }
.dbbackup-recent-row { padding: .5rem 0; }
</style>
