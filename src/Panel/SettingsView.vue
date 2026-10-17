<template>
  <k-panel-inside class="k-newsletter-view k-newsletter-settings-view">
    <k-header>
      {{ $t("e9li.newsletter.tab.settings") }}
    </k-header>

    <k-newsletter-nav :tab="tab" :tabs="tabs" />

    <k-section :headline="$t('e9li.newsletter.settings.readiness')">
      <k-box theme="info" icon="info" class="k-newsletter-notice">
        <k-text>
          {{ $t("e9li.newsletter.settings.readonly") }}
        </k-text>
      </k-box>

      <k-table :columns="checkColumns" :rows="checkRows" :index="false" :empty="'—'" />
    </k-section>

    <k-section :headline="$t('e9li.newsletter.settings.sending')">
      <k-definitions>
        <k-definition
          v-for="item in sendingItems"
          :key="item.term"
          :term="item.term"
        >
          {{ item.description }}
        </k-definition>
      </k-definitions>
    </k-section>

    <k-section :headline="$t('e9li.newsletter.settings.storage')">
      <k-definitions>
        <k-definition :term="$t('e9li.newsletter.settings.root')">
          <code>{{ storage.root }}</code>
        </k-definition>
        <k-definition :term="$t('e9li.newsletter.settings.schema')">
          {{ storage.schema }}
        </k-definition>
        <k-definition :term="$t('e9li.newsletter.settings.size')">
          {{ size }}
        </k-definition>
        <k-definition :term="$t('e9li.newsletter.settings.journal')">
          {{ storage.journal }}
          <k-text v-if="storage.journal !== 'wal'" size="small" theme="negative">
            {{ $t("e9li.newsletter.settings.journalWarning") }}
          </k-text>
        </k-definition>
        <k-definition :term="$t('e9li.newsletter.settings.version')">
          {{ storage.version }}
        </k-definition>
      </k-definitions>
    </k-section>
  </k-panel-inside>
</template>

<script>
export default {
  name: "k-newsletter-settings-view",

  props: {
    tab: String,
    tabs: { type: Array, default: () => [] },
    checks: { type: Array, default: () => [] },
    storage: { type: Object, default: () => ({}) },
    sending: { type: Object, default: () => ({}) }
  },

  computed: {
    checkColumns() {
      return {
        label: { label: this.$t("e9li.newsletter.settings.setting"), type: "text", width: "1/4", mobile: true },
        value: { label: this.$t("e9li.newsletter.settings.value"), type: "text", mobile: true },
        help: { label: "", type: "text" }
      };
    },

    checkRows() {
      return this.checks.map((check) => ({
        // A leading marker is used rather than a themed cell because k-table
        // cells have no per-row theme; the flag column would otherwise be
        // indistinguishable from ordinary text.
        label: (check.status === "positive" ? "✓ " : "✕ ") + check.label,
        value: check.value,
        help: check.help ?? ""
      }));
    },

    size() {
      const bytes = this.storage.size ?? 0;
      return bytes > 1048576
        ? (bytes / 1048576).toFixed(1) + " MB"
        : Math.round(bytes / 1024) + " KB";
    },

    sendingItems() {
      const t = (key) => this.$t("e9li.newsletter.settings." + key);
      const yesNo = (v) => (v ? this.$t("yes") : this.$t("no"));

      return [
        { term: t("transport"), description: this.sending.transport },
        {
          term: t("rate"),
          description:
            this.sending.ratePerHour +
            "/h · " +
            this.$t("e9li.newsletter.settings.batch") +
            " " +
            this.sending.batchSize
        },
        { term: t("warmup"), description: yesNo(this.sending.warmup) },
        { term: t("optin"), description: yesNo(this.sending.optin) },
        { term: t("trackOpens"), description: yesNo(this.sending.trackOpens) },
        { term: t("trackClicks"), description: yesNo(this.sending.trackClicks) },
        { term: t("storeIp"), description: this.sending.storeIp }
      ];
    }
  }
};
</script>

<style>
.k-newsletter-settings-view code {
  font-size: var(--text-xs);
  word-break: break-all;
}
</style>
