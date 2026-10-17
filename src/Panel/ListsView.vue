<template>
  <k-panel-inside class="k-newsletter-view k-newsletter-lists-view">
    <k-header>
      {{ $t("e9li.newsletter.tab.lists") }}
      <template #buttons>
        <k-button-group>
          <k-button
            :text="$t('e9li.newsletter.action.import')"
            icon="import"
            size="sm"
            variant="filled"
            dialog="newsletter/import"
          />
          <k-button
            :text="$t('e9li.newsletter.action.export')"
            icon="download"
            size="sm"
            variant="filled"
            dialog="newsletter/export"
          />
          <k-button
            :text="$t('e9li.newsletter.action.suppress')"
            icon="cancel"
            size="sm"
            variant="filled"
            theme="negative"
            dialog="newsletter/suppress"
          />
          <k-button
            :text="$t('e9li.newsletter.action.reconsent')"
            icon="refresh"
            size="sm"
            variant="filled"
            dialog="newsletter/reconsent"
          />
          <k-button
            :text="$t('e9li.newsletter.suppressions.import')"
            icon="import"
            size="sm"
            variant="filled"
            dialog="newsletter/suppressions/import"
          />
          <k-button
            v-if="parent"
            :text="$t('e9li.newsletter.action.manageLists')"
            :link="parent"
            icon="cog"
            size="sm"
            variant="filled"
          />
        </k-button-group>
      </template>
    </k-header>

    <k-newsletter-nav :tab="tab" :tabs="tabs" />

    <k-stats :reports="reports" size="small" class="k-newsletter-stats" />

    <k-section :headline="$t('e9li.newsletter.tab.lists')">
      <k-empty
        v-if="lists.length === 0"
        icon="users"
        :text="$t('e9li.newsletter.lists.empty')"
        @click="openParent"
      />
      <k-items
        v-else
        layout="cards"
        :items="listItems"
        :columns="3"
      />
    </k-section>

    <k-section :headline="$t('e9li.newsletter.suppressions.headline')">
      <k-table
        :columns="suppressionColumns"
        :rows="suppressionRows"
        :pagination="suppressionTablePagination"
        :empty="$t('e9li.newsletter.suppressions.empty')"
        @paginate="onSuppressPaginate"
        @cell="onSuppressCell"
      />
    </k-section>

    <k-section :headline="$t('e9li.newsletter.subscribers.headline')">
      <k-newsletter-subscriber-table
        :lists="lists"
        :languages="languages"
        :statuses="statuses"
      />
    </k-section>
  </k-panel-inside>
</template>

<script>
export default {
  name: "k-newsletter-lists-view",

  props: {
    tab: String,
    tabs: { type: Array, default: () => [] },
    lists: { type: Array, default: () => [] },
    parent: String,
    languages: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) }
  },

  data() {
    return {
      suppressions: [],
      suppressionPagination: { page: 1, limit: 25, total: 0, offset: 0 }
    };
  },

  created() {
    this.$events.on("newsletter.suppressions", this.loadSuppressions);
    this.$events.on("newsletter.subscriber.update", this.loadSuppressions);
  },

  destroyed() {
    this.$events.off("newsletter.suppressions", this.loadSuppressions);
    this.$events.off("newsletter.subscriber.update", this.loadSuppressions);
  },

  mounted() {
    this.loadSuppressions();
  },

  computed: {
    reports() {
      // k-stat's `value` prop is String-typed, so numbers are formatted here
      // rather than passed raw.
      return [
        {
          label: this.$t("e9li.newsletter.stat.active"),
          value: this.format(this.totals.active),
          icon: "check",
          theme: "positive"
        },
        {
          label: this.$t("e9li.newsletter.stat.pending"),
          value: this.format(this.totals.pending),
          info: this.$t("e9li.newsletter.stat.pendingInfo"),
          icon: "clock"
        },
        {
          label: this.$t("e9li.newsletter.stat.lists"),
          value: this.format(this.lists.length),
          icon: "users"
        },
        {
          label: this.$t("e9li.newsletter.stat.suppressed"),
          value: this.format(this.totals.suppressed),
          info: this.$t("e9li.newsletter.stat.suppressedInfo"),
          icon: "cancel",
          theme: this.totals.suppressed > 0 ? "negative" : null
        }
      ];
    },

    suppressionColumns() {
      return {
        address: { label: this.$t("email"), type: "text", mobile: true },
        reason: { label: this.$t("e9li.newsletter.column.reason"), type: "text", mobile: true },
        date: { label: this.$t("e9li.newsletter.column.date"), type: "text" },
        expires: { label: this.$t("e9li.newsletter.suppressions.expires"), type: "text" },
        options: { label: " ", type: "options", width: "1/12" }
      };
    },

    suppressionRows() {
      return this.suppressions.map((row) => ({
        ...row,
        options: [
          {
            icon: "trash",
            text: this.$t("e9li.newsletter.suppressions.remove"),
            click: () => this.$dialog(row.link)
          }
        ]
      }));
    },

    suppressionTablePagination() {
      return {
        page: this.suppressionPagination.page,
        limit: this.suppressionPagination.limit,
        total: this.suppressionPagination.total,
        details: true
      };
    },

    listItems() {
      // k-item's title prop is `text`, not `title` — there is no `title` prop,
      // so passing one renders "undefined".
      return this.lists.map((list) => ({
        id: list.uuid,
        text: list.title,
        info: this.listInfo(list),
        image: { icon: "users", back: list.public ? "var(--color-blue-300)" : "var(--color-gray-300)" },
        link: list.link
      }));
    }
  },

  methods: {
    format(value) {
      return new Intl.NumberFormat().format(value ?? 0);
    },

    listInfo(list) {
      const parts = [
        list.active + " " + this.$t("e9li.newsletter.list.active").toLowerCase()
      ];

      if (list.pending > 0) {
        parts.push(list.pending + " " + this.$t("e9li.newsletter.stat.pending").toLowerCase());
      }

      if (list.public) {
        parts.push(this.$t("e9li.newsletter.list.publicShort"));
      }

      return parts.join(" · ");
    },

    openParent() {
      if (this.parent) {
        this.$go(this.parent);
      }
    },

    async loadSuppressions() {
      try {
        const response = await this.$panel.get("requests/newsletter/suppressions", {
          query: {
            page: this.suppressionPagination.page,
            limit: this.suppressionPagination.limit
          }
        });

        this.suppressions = response.data ?? [];
        this.suppressionPagination = response.pagination ?? this.suppressionPagination;
      } catch (error) {
        this.$panel.error(error);
      }
    },

    onSuppressPaginate(pagination) {
      this.suppressionPagination = { ...this.suppressionPagination, ...pagination };
      this.loadSuppressions();
    },

    onSuppressCell({ columnIndex, row }) {
      if (columnIndex === "options") {
        return;
      }

      if (row.link) {
        this.$dialog(row.link);
      }
    }
  }
};
</script>

<style>
.k-newsletter-view .k-tabs {
  margin-bottom: var(--spacing-6);
}
.k-newsletter-stats {
  margin-bottom: var(--spacing-8);
}

/* The header button row is flex-shrink: 0, so six labelled actions
   widen the whole Panel on a phone. Let that row wrap inside the view. */
.k-newsletter-lists-view .k-header {
  flex-wrap: wrap;
  align-items: flex-start;
}
.k-newsletter-lists-view .k-header-buttons {
  flex-shrink: 1;
  min-width: 0;
  max-width: 100%;
}
.k-newsletter-lists-view .k-header-buttons .k-button-group {
  justify-content: flex-end;
  max-width: 100%;
}
</style>
