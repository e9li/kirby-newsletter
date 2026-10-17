<template>
  <k-panel-inside class="k-newsletter-view k-newsletter-templates-view">
    <k-header>
      {{ $t("e9li.newsletter.tab.templates") }}
      <template #buttons>
        <k-button-group>
          <k-button
            v-if="parent"
            :text="$t('e9li.newsletter.action.newTemplate')"
            :link="parent"
            icon="add"
            size="sm"
            variant="filled"
          />
        </k-button-group>
      </template>
    </k-header>

    <k-newsletter-nav :tab="tab" :tabs="tabs" />

    <k-box theme="notice" icon="info" class="k-newsletter-notice">
      <k-text>
        {{ $t("e9li.newsletter.templates.info") }}
      </k-text>
    </k-box>

    <k-empty
      v-if="templates.length === 0"
      icon="template"
      :text="$t('e9li.newsletter.templates.empty')"
      @click="openParent"
    />

    <k-items
      v-else
      layout="cards"
      :columns="3"
      :items="items"
    />
  </k-panel-inside>
</template>

<script>
export default {
  name: "k-newsletter-templates-view",

  props: {
    tab: String,
    tabs: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    parent: String
  },

  computed: {
    items() {
      // k-item's title prop is `text`, not `title`.
      return this.templates.map((template) => ({
        id: template.uuid,
        text: template.title,
        // The legal flag is the one thing worth surfacing at a glance: a theme
        // without a legal name and postal address cannot lawfully be sent.
        info: template.legal
          ? this.$t("e9li.newsletter.templates.legalOk")
          : this.$t("e9li.newsletter.templates.legalMissing"),
        image: {
          icon: "palette",
          back: template.brand || "var(--color-gray-300)"
        },
        link: template.link
      }));
    }
  },

  methods: {
    openParent() {
      if (this.parent) {
        this.$go(this.parent);
      }
    }
  }
};
</script>
