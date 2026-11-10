import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'

document.addEventListener('DOMContentLoaded', () => {
  const rootEl = document.getElementById('mumb-health-dashboard-root')
  if (!rootEl) {
    return
  }
  const config = window.MUMB_HEALTH_DASHBOARD || {}
  const root = createRoot(rootEl)
  root.render(<App config={config} />)
})
