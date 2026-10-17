<template>
  <k-dialog
    ref="dialog"
    :visible="visible"
    size="huge"
    :submit-button="false"
    :cancel-button="true"
    @cancel="$emit('cancel')"
    @close="$emit('close')"
  >
    <k-headline>{{ title || $t("e9li.newsletter.preview.title") }}</k-headline>
    <k-text class="k-newsletter-preview-meta">
      {{ bytes }} {{ $t("e9li.newsletter.preview.bytes") }}
    </k-text>
    <k-box v-if="warning" theme="notice" icon="alert">
      <k-text>{{ warning }}</k-text>
    </k-box>
    <iframe
      class="k-newsletter-preview-frame"
      sandbox=""
      :srcdoc="html"
      :title="$t('e9li.newsletter.preview.title')"
    />
  </k-dialog>
</template>

<script>
export default {
  // Kirby 5 fiber dialogs pass `visible` onto the component. k-dialog only
  // teleports its body while that prop is true, and its own open() asks the
  // panel to open a legacy dialog, so this wrapper must forward `visible`
  // and must not call `$refs.dialog.open()`.
  props: {
    title: String,
    html: { type: String, default: "" },
    bytes: { type: Number, default: 0 },
    warning: { type: String, default: "" },
    visible: { type: Boolean, default: false }
  },

  methods: {
    open() {
      this.$emit("open");
    },

    close() {
      this.$emit("cancel");
    }
  }
};
</script>

<style>
.k-newsletter-preview-meta {
  margin-bottom: var(--spacing-3);
}

.k-newsletter-preview-frame {
  width: 100%;
  height: 70vh;
  border: 1px solid var(--color-border);
  background: var(--color-white);
}
</style>
