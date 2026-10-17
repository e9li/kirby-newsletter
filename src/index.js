import Nav from "./panel/Nav.vue";
import SubscriberTable from "./panel/SubscriberTable.vue";
import CampaignsView from "./panel/CampaignsView.vue";
import ListsView from "./panel/ListsView.vue";
import TemplatesView from "./panel/TemplatesView.vue";
import SettingsView from "./panel/SettingsView.vue";
import PreviewDialog from "./panel/PreviewDialog.vue";

/**
 * Panel registration.
 *
 * Note there is no `views` key in Kirby 5 — `panel.plugin()` never reads one.
 * A view is registered as an ordinary global component and referenced by name
 * from the PHP area route's `component` key.
 *
 * Icons are a JS-only extension; the PHP `icons` key that worked in Kirby 4 is
 * not in Kirby 5's extension registry and is silently ignored.
 *
 * The Panel already ships the RemixIcon set (225 icons, Apache 2.0 — see
 * kirby/panel/dist/img/icons.svg), so `email`, `users`, `template`, `settings`,
 * `megaphone`, `import`, `download` and friends are used directly by name.
 * Only genuinely missing glyphs are registered below.
 */
panel.plugin("e9li/kirby-newsletter", {
  components: {
    "k-newsletter-nav": Nav,
    "k-newsletter-subscriber-table": SubscriberTable,
    "k-newsletter-campaigns-view": CampaignsView,
    "k-newsletter-lists-view": ListsView,
    "k-newsletter-templates-view": TemplatesView,
    "k-newsletter-settings-view": SettingsView,
    "k-newsletter-preview-dialog": PreviewDialog
  },

  icons: {
    // RemixIcon `mail-send-line`, on the 24×24 grid Kirby's <symbol> assumes.
    // Content is bare SVG children — a wrapping <svg> would nest and break sizing.
    "nl-send": `<path d="M3 13h6v-2H3V1.846a.5.5 0 0 1 .741-.439l18.462 10.155a.5.5 0 0 1 0 .876L3.741 22.593A.5.5 0 0 1 3 22.154V13Z"/>`,

    // RemixIcon `mail-open-line`
    "nl-opened": `<path d="M21 20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.352a1 1 0 0 1 .445-.832l8-5.333a1 1 0 0 1 1.11 0l8 5.333a1 1 0 0 1 .445.832V20Zm-2-1v-8.58l-6.997-4.665L5 10.42V19h14Zm-8.964-4.617 3.923-3.311 1.29 1.529-5.213 4.4-4.53-4.4 1.392-1.433 3.138 3.215Z"/>`,

    // RemixIcon `spam-2-line` — used for the suppression list
    "nl-suppressed": `<path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4Zm0 2.18 7 3.11V11c0 4.52-2.98 8.69-7 9.93C7.98 19.69 5 15.52 5 11V6.29l7-3.11ZM11 7h2v6h-2V7Zm0 8h2v2h-2v-2Z"/>`
  }
});
