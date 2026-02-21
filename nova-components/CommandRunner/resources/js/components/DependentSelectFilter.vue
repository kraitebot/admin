<template>
  <FilterContainer>
    <span>{{ filter.name }}</span>

    <template #filter>
      <select
        v-model="value"
        class="block w-full form-control form-control-bordered form-input form-input-bordered form-select"
        :dusk="filter.uniqueKey"
        style="font-size: 13px;"
      >
        <option value="">&mdash;</option>
        <option
          v-for="option in dynamicOptions"
          :key="option.value"
          :value="option.value"
        >
          {{ option.label }}
        </option>
      </select>
    </template>
  </FilterContainer>
</template>

<script>
import debounce from 'lodash/debounce'

export default {
  emits: ['change'],

  props: {
    resourceName: {
      type: String,
      required: true,
    },
    filterKey: {
      type: String,
      required: true,
    },
    lens: String,
  },

  data: () => ({
    value: '',
    dynamicOptions: [],
    debouncedEventEmitter: null,
  }),

  created() {
    this.debouncedEventEmitter = debounce(() => this.emitFilterChange(), 500)

    if (this.filter.currentValue) {
      this.value = this.filter.currentValue
    }
  },

  mounted() {
    Nova.$on('filter-reset', this.handleFilterReset)

    // Initial load if type is already selected
    this.fetchOptions()
  },

  beforeUnmount() {
    Nova.$off('filter-reset', this.handleFilterReset)
  },

  watch: {
    value() {
      this.debouncedEventEmitter()
    },

    dependsOnValue(newVal, oldVal) {
      if (newVal !== oldVal) {
        this.value = ''
        this.fetchOptions()
      }
    },
  },

  methods: {
    emitFilterChange() {
      this.$store.commit(`${this.resourceName}/updateFilterState`, {
        filterClass: this.filterKey,
        value: this.value ?? '',
      })

      this.$emit('change')
    },

    handleFilterReset() {
      this.value = ''
      this.dynamicOptions = []
    },

    async fetchOptions() {
      const type = this.dependsOnValue

      if (!type) {
        this.dynamicOptions = []
        return
      }

      const table = this.filter.table || 'api_request_logs'

      try {
        const response = await fetch(
          `/nova-vendor/command-runner/relatable-models?type=${encodeURIComponent(type)}&table=${encodeURIComponent(table)}`
        )
        const data = await response.json()
        this.dynamicOptions = data
      } catch (e) {
        this.dynamicOptions = []
      }
    },
  },

  computed: {
    filter() {
      return this.$store.getters[`${this.resourceName}/getFilter`](
        this.filterKey
      )
    },

    dependsOnValue() {
      const dependsOnKey = this.filter.dependsOnKey
      if (!dependsOnKey) return null

      const allFilters = this.$store.getters[`${this.resourceName}/filters`]
      const parentFilter = allFilters.find(f => f.class === dependsOnKey)

      return parentFilter ? parentFilter.currentValue : null
    },
  },
}
</script>
