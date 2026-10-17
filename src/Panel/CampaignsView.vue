<template>
  <k-panel-inside class="k-newsletter-view k-newsletter-campaigns-view">
    <k-header>
      {{ $t("e9li.newsletter.label") }}
      <template #buttons>
        <k-button-group>
          <k-button
            v-if="parent"
            :text="$t('e9li.newsletter.action.newCampaign')"
            :link="parent"
            icon="add"
            size="sm"
            variant="filled"
          />
        </k-button-group>
      </template>
    </k-header>

    <k-newsletter-nav :tab="tab" :tabs="tabs" />

    <k-empty
      v-if="campaigns.length === 0"
      icon="megaphone"
      :text="$t('e9li.newsletter.campaigns.empty')"
      @click="openParent"
    />

    <k-table
      v-else
      :columns="columns"
      :rows="rows"
      :empty="$t('e9li.newsletter.campaigns.empty')"
      @cell="onCell"
    />
  </k-panel-inside>
</template>

<script>
export default {
  name: "k-newsletter-campaigns-view",

  props: {
    tab: String,
    tabs: { type: Array, default: () => [] },
    campaigns: { type: Array, default: () => [] },
    parent: String
  },

  created() {
    this.$events.on("newsletter.campaigns", this.reload);
  },

  destroyed() {
    this.$events.off("newsletter.campaigns", this.reload);
  },

  computed: {
    columns() {
      return {
        title: { label: this.$t("title"), type: "text", mobile: true },
        subject: { label: this.$t("e9li.newsletter.column.subject"), type: "text" },
        status: { label: this.$t("status"), type: "text", width: "1/8", mobile: true },
        sent: { label: this.$t("e9li.newsletter.column.sent"), type: "text", width: "1/8", mobile: true },
        failed: { label: this.$t("e9li.newsletter.column.failed"), type: "text", width: "1/12" },
        opened: { label: this.$t("e9li.newsletter.column.opened"), type: "text", width: "1/8" },
        options: { label: " ", type: "options", width: "1/12" }
      };
    },

    rows() {
      return this.campaigns.map((campaign) => ({
        ...campaign,
        subject: campaign.subject || "—",
        languages: campaign.languages.length ? campaign.languages.join(", ") : "—",
        status: this.$t("page.status." + campaign.status),
        options: this.actions(campaign)
      }));
    }
  },

  methods: {
    actions(campaign) {
      return [
        {
          icon: "preview",
          text: this.$t("e9li.newsletter.action.preview"),
          click: () => this.$dialog("newsletter/preview/" + campaign.id)
        },
        {
          icon: "email",
          text: this.$t("e9li.newsletter.action.sendTest"),
          click: () => this.$dialog("newsletter/test/" + campaign.id)
        },
        {
          icon: "plane",
          text: this.$t("e9li.newsletter.action.send"),
          click: () => this.$dialog("newsletter/send/" + campaign.id)
        },
        {
          icon: "chart",
          text: this.$t("e9li.newsletter.action.report"),
          click: () => this.$dialog("newsletter/report/" + campaign.id)
        }
      ];
    },

    onCell({ columnIndex, row }) {
      if (columnIndex === "options") {
        return;
      }

      if (row.link) {
        this.$go(row.link);
      }
    },

    openParent() {
      if (this.parent) {
        this.$go(this.parent);
      }
    },

    reload() {
      if (typeof this.$reload === "function") {
        this.$reload();
        return;
      }

      this.$panel.open(window.location.href);
    }
  }
};
</script>
