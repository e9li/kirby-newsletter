<template>
  <div class="k-newsletter-subscribers">
    <k-bar class="k-newsletter-subscribers-bar">
      <k-input
        :value="searchterm"
        :placeholder="$t('filter') + ' …'"
        type="text"
        icon="search"
        @input="onSearch"
      />

      <k-button-group layout="collapsed">
        <k-button
          :text="statusLabel"
          icon="filter"
          size="sm"
          variant="filled"
          :dropdown="true"
          @click="$refs.status.toggle()"
        />
        <k-dropdown-content ref="status" :options="statusOptions" />
      </k-button-group>

      <k-button-group v-if="lists.length" layout="collapsed">
        <k-button
          :text="listLabel"
          icon="users"
          size="sm"
          variant="filled"
          :dropdown="true"
          @click="$refs.list.toggle()"
        />
        <k-dropdown-content ref="list" :options="listOptions" />
      </k-button-group>
    </k-bar>

    <k-table
      :columns="columns"
      :rows="rows"
      :index="pagination.offset + 1"
      :empty="emptyText"
      :pagination="tablePagination"
      :options="rowOptions"
      @paginate="onPaginate"
      @header="onHeader"
      @option="onOption"
    />

    <k-text v-if="isLoading" class="k-newsletter-loading" size="small">
      {{ $t("loading") }} …
    </k-text>
  </div>
</template>

<script>
export default {
  name: "k-newsletter-subscriber-table",

  props: {
    lists: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] }
  },

  data() {
    return {
      rows: [],
      // `offset` is what feeds k-table's `index`, so the row numbers keep
      // counting up across pages instead of restarting at 1 on every page.
      pagination: { page: 1, limit: 50, total: 0, offset: 0 },
      searchterm: "",
      status: null,
      list: null,
      sort: "created_at",
      direction: "desc",
      isLoading: false
    };
  },

  computed: {
    columns() {
      // Must be an OBJECT keyed by column id, not an array: k-table uses the
      // key both as the <th> id and to look up row[key].
      return {
        email: { label: this.$t("email"), type: "text", mobile: true },
        name: { label: this.$t("name"), type: "text" },
        status: { label: this.$t("status"), type: "text", width: "1/8", mobile: true },
        language: { label: this.$t("language"), type: "text", width: "1/12" },
        lists: { label: this.$t("e9li.newsletter.tab.lists"), type: "text", width: "1/12", align: "right" },
        confirmed: { label: this.$t("e9li.newsletter.column.confirmed"), type: "text", width: "1/8" }
      };
    },

    tablePagination() {
      return {
        page: this.pagination.page,
        limit: this.pagination.limit,
        total: this.pagination.total,
        details: true
      };
    },

    emptyText() {
      // k-table's `empty` is a plain String (unlike k-collection's object).
      return this.searchterm || this.status || this.list
        ? this.$t("search.results.none")
        : this.$t("e9li.newsletter.subscribers.empty");
    },

    statusLabel() {
      const match = this.statuses.find((s) => s.value === this.status);
      return match ? match.text : this.$t("e9li.newsletter.filter.allStatuses");
    },

    listLabel() {
      const match = this.lists.find((l) => l.uuid === this.list);
      return match ? match.title : this.$t("e9li.newsletter.filter.allLists");
    },

    statusOptions() {
      return [
        {
          text: this.$t("e9li.newsletter.filter.allStatuses"),
          current: this.status === null,
          click: () => this.setFilter("status", null)
        },
        ...this.statuses.map((s) => ({
          text: s.text,
          current: this.status === s.value,
          click: () => this.setFilter("status", s.value)
        }))
      ];
    },

    listOptions() {
      return [
        {
          text: this.$t("e9li.newsletter.filter.allLists"),
          current: this.list === null,
          click: () => this.setFilter("list", null)
        },
        ...this.lists.map((l) => ({
          text: l.title,
          current: this.list === l.uuid,
          click: () => this.setFilter("list", l.uuid)
        }))
      ];
    },

    rowOptions() {
      return (row) => [
        {
          icon: "cancel",
          text: this.$t("e9li.newsletter.action.unsubscribe"),
          dialog: "newsletter/subscriber/" + row.id + "/unsubscribe"
        },
        {
          icon: "trash",
          text: this.$t("delete"),
          dialog: "newsletter/subscriber/" + row.id + "/delete"
        }
      ];
    }
  },

  created() {
    // Debounced so typing a filter does not fire a request per keystroke.
    this.debouncedLoad = this.$helper.debounce(this.load, 250);

    // Reload after any dialog that changed a subscriber.
    this.$events.on("newsletter.subscriber.update", this.load);
    this.$events.on("newsletter.subscriber.delete", this.load);
    this.$events.on("newsletter.import", this.load);
  },

  destroyed() {
    this.$events.off("newsletter.subscriber.update", this.load);
    this.$events.off("newsletter.subscriber.delete", this.load);
    this.$events.off("newsletter.import", this.load);
  },

  mounted() {
    this.load();
  },

  methods: {
    async load() {
      this.isLoading = true;

      try {
        const response = await this.$panel.get("requests/newsletter/subscribers", {
          query: {
            page: this.pagination.page,
            limit: this.pagination.limit,
            searchterm: this.searchterm,
            status: this.status,
            list: this.list,
            sort: this.sort,
            direction: this.direction
          }
        });

        this.rows = response.data ?? [];
        this.pagination = response.pagination ?? this.pagination;
      } catch (error) {
        // $panel.error handles offline, redirect and abort correctly, where a
        // bare notification would show "Something went wrong" for all three.
        this.$panel.error(error);
      } finally {
        this.isLoading = false;
      }
    },

    onSearch(value) {
      this.searchterm = value;
      this.pagination.page = 1;
      this.debouncedLoad();
    },

    setFilter(key, value) {
      this[key] = value;
      this.pagination.page = 1;
      this.load();
    },

    onPaginate(pagination) {
      this.pagination = { ...this.pagination, ...pagination };
      this.load();
    },

    onHeader({ columnIndex }) {
      // k-table has no built-in column sorting — `sortable`/@sort are drag
      // reordering. Click-to-sort has to be wired up from @header, and
      // columnIndex is the column KEY string, not a number.
      const column = { email: "email_norm", confirmed: "confirmed_at" }[columnIndex] ?? columnIndex;

      if (this.sort === column) {
        this.direction = this.direction === "asc" ? "desc" : "asc";
      } else {
        this.sort = column;
        this.direction = "asc";
      }

      this.pagination.page = 1;
      this.load();
    },

    onOption(option, row) {
      // @option arrives as three positional args, not an object.
      if (option.dialog) {
        this.$dialog(option.dialog);
      }
    }
  }
};
</script>

<style>
.k-newsletter-subscribers-bar {
  margin-bottom: var(--spacing-3);
  gap: var(--spacing-2);
}
.k-newsletter-subscribers-bar .k-input {
  flex: 1 1 16rem;
}
.k-newsletter-loading {
  margin-top: var(--spacing-2);
  color: var(--color-text-dimmed);
}
</style>
