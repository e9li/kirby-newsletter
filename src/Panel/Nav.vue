<template>
  <!--
    Sub-navigation for the area.

    Kirby 5's sidebar is flat — k-panel-menu renders a plain list of buttons
    split only by "-" separators, with no children key — so the four sections
    live here instead. k-tabs navigates via each tab's `link` and emits no
    change event, which means every tab is a real Panel route: bookmarkable,
    reloadable, and back-button friendly.
  -->
  <k-tabs :tab="tab" :tabs="tabs" />
</template>

<script>
export default {
  name: "k-newsletter-nav",
  props: {
    tab: String,
    tabs: { type: Array, default: () => [] }
  }
};
</script>
