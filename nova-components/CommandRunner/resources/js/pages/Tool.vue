<template>
  <div>
    <Head title="Command Runner" />

    <Heading class="mb-6">Command Runner</Heading>

    <LoadingCard :loading="loading">
      <Card
        v-for="(meta, command) in commands"
        :key="command"
        class="mb-6"
      >
        <div class="p-6">
          <!-- Header -->
          <div class="mb-4">
            <h3 class="text-lg font-semibold dark:text-gray-100">
              <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-sm font-mono">{{ command }}</code>
            </h3>
            <p class="text-gray-500 dark:text-gray-400 mt-2 text-sm">{{ meta.description }}</p>
          </div>

          <!-- Options -->
          <div v-if="Object.keys(meta.options).length" class="mb-5 space-y-4">
            <div
              v-for="(opt, key) in meta.options"
              :key="key"
              class="flex items-center gap-3"
            >
              <!-- Boolean -->
              <template v-if="opt.type === 'boolean'">
                <Checkbox
                  :checked="form[command][key]"
                  @input="form[command][key] = $event.target.checked"
                  :disabled="statuses[command] === 'running'"
                />
                <span class="dark:text-gray-300">
                  <code class="font-mono text-sm">{{ key }}</code>
                  <span class="text-gray-400 ml-1">— {{ opt.description }}</span>
                </span>
              </template>

              <!-- Select -->
              <template v-else-if="opt.type === 'select'">
                <Checkbox
                  :checked="enabled[command][key]"
                  @input="toggleSelect(command, key, $event.target.checked)"
                  :disabled="statuses[command] === 'running'"
                />
                <span class="dark:text-gray-300 whitespace-nowrap">
                  <code class="font-mono text-sm">{{ key }}</code>
                </span>
                <select
                  v-model="form[command][key]"
                  :disabled="statuses[command] === 'running' || !enabled[command][key]"
                  class="form-control form-control-bordered form-input"
                  :style="{ width: '14rem', paddingLeft: '0.75rem', paddingRight: '2rem', marginLeft: '0.5rem', marginRight: '0.5rem', opacity: enabled[command][key] ? 1 : 0.4 }"
                  @change="onSelectChange(command, key)"
                >
                  <option value="">All (default)</option>
                  <option v-for="choice in opt.choices" :key="choice" :value="choice">
                    {{ choice }}
                  </option>
                </select>
                <span class="text-gray-400 text-sm">{{ opt.description }}</span>
              </template>

              <!-- Text -->
              <template v-else-if="opt.type === 'text'">
                <Checkbox
                  :checked="enabled[command][key]"
                  @input="toggleSelect(command, key, $event.target.checked)"
                  :disabled="statuses[command] === 'running'"
                />
                <span class="dark:text-gray-300 whitespace-nowrap">
                  <code class="font-mono text-sm">{{ key }}</code>
                </span>
                <input
                  type="text"
                  v-model="form[command][key]"
                  :disabled="statuses[command] === 'running' || !enabled[command][key]"
                  class="form-control form-control-bordered form-input"
                  :style="{ width: '14rem', paddingLeft: '0.75rem', paddingRight: '0.75rem', marginLeft: '0.5rem', marginRight: '0.5rem', opacity: enabled[command][key] ? 1 : 0.4 }"
                  :placeholder="opt.description"
                  @input="onTextInput(command, key)"
                />
                <span class="text-gray-400 text-sm">{{ opt.description }}</span>
              </template>
            </div>
          </div>

          <!-- Run Button + Status -->
          <div class="flex items-center gap-3">
            <button
              @click="run(command)"
              :disabled="statuses[command] === 'running'"
              class="shadow relative bg-primary-500 hover:bg-primary-400 text-white dark:text-gray-900 rounded-lg font-bold text-sm h-9 inline-flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-primary-200 disabled:opacity-50 disabled:cursor-not-allowed active:bg-primary-600 transition"
              style="padding-left: 1.25rem; padding-right: 1.25rem;"
            >
              <Loader v-if="statuses[command] === 'running'" class="mr-1" width="18" />
              {{ statuses[command] === 'running' ? 'Running...' : 'Run Command' }}
            </button>

            <Badge v-if="statuses[command] === 'success'" type="pill" variant="success">
              Success (exit {{ exitCodes[command] }})
            </Badge>
            <Badge v-else-if="statuses[command] === 'error'" type="pill" variant="danger">
              Error (exit {{ exitCodes[command] }})
            </Badge>
          </div>
        </div>

        <!-- Output -->
        <div
          v-if="outputs[command]"
          class="border-t border-gray-100 dark:border-gray-700"
        >
          <pre class="bg-gray-900 text-green-400 p-4 font-mono text-sm overflow-auto whitespace-pre-wrap rounded-b-lg" style="max-height: 400px">{{ outputs[command] }}</pre>
        </div>
      </Card>
    </LoadingCard>
  </div>
</template>

<script>
export default {
  data() {
    return {
      loading: true,
      commands: {},
      form: {},
      enabled: {},
      statuses: {},
      outputs: {},
      exitCodes: {},
    }
  },

  async mounted() {
    await this.fetchCommands()
  },

  methods: {
    async fetchCommands() {
      try {
        const { data } = await Nova.request().get('/nova-vendor/command-runner')
        this.commands = data

        for (const [command, meta] of Object.entries(data)) {
          this.form[command] = {}
          this.enabled[command] = {}
          for (const [key, opt] of Object.entries(meta.options)) {
            this.form[command][key] = opt.type === 'boolean' ? false : ''
            if (opt.type === 'select' || opt.type === 'text') this.enabled[command][key] = false
          }
          this.statuses[command] = 'idle'
          this.outputs[command] = ''
          this.exitCodes[command] = null
        }
      } catch (e) {
        Nova.error('Failed to load commands.')
      } finally {
        this.loading = false
      }
    },

    onSelectChange(command, key) {
      if (this.form[command][key] !== '') {
        this.enabled[command][key] = true
      }
    },

    onTextInput(command, key) {
      if (this.form[command][key] !== '') {
        this.enabled[command][key] = true
      }
    },

    toggleSelect(command, key, checked) {
      this.enabled[command][key] = checked
      if (!checked) {
        this.form[command][key] = ''
      }
    },

    async run(command) {
      this.statuses[command] = 'running'
      this.outputs[command] = ''
      this.exitCodes[command] = null

      try {
        const options = {}
        const meta = this.commands[command]
        for (const [key, val] of Object.entries(this.form[command])) {
          const type = meta.options[key].type
          if ((type === 'select' || type === 'text') && !this.enabled[command][key]) continue
          options[key] = val
        }
        const { data } = await Nova.request().post('/nova-vendor/command-runner/run', {
          command,
          options,
        })

        this.exitCodes[command] = data.exit_code
        this.outputs[command] = data.output || '(no output)'
        this.statuses[command] = data.exit_code === 0 ? 'success' : 'error'
      } catch (e) {
        this.statuses[command] = 'error'
        this.exitCodes[command] = 1
        this.outputs[command] = e.response?.data?.output || e.response?.data?.error || e.message
      }
    },
  },
}
</script>
