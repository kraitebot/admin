import Tool from './pages/Tool'
import DependentSelectFilter from './components/DependentSelectFilter'

Nova.inertia('CommandRunner', Tool)

Nova.booting((app, store) => {
  app.component('dependent-select-filter', DependentSelectFilter)
})
