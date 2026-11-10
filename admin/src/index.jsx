import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'

document.addEventListener('DOMContentLoaded', () => {
  try {
    const v = (window.MWP_BOOTSTRAP && window.MWP_BOOTSTRAP.version) ? window.MWP_BOOTSTRAP.version : 'dev'
    const bt = window.MWP_BOOTSTRAP && window.MWP_BOOTSTRAP.build ? window.MWP_BOOTSTRAP.build : null
    const tag = bt ? `${v} | ${bt.time||''} | ${bt.hash||''}` : v
    // eslint-disable-next-line no-console
    console.log(`[MWP] MikroTik Manager admin bundle loaded — ${tag}`)
  } catch (e) { /* noop */ }
  const el = document.getElementById('mwp-admin-root')
  if (el) {
    const root = createRoot(el)
    root.render(<App />)
  }
})
