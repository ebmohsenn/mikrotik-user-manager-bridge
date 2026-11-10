import React from 'react'

const STATUS_META = (strings) => ({
  online: {
    label: strings.statusOnline || 'Online',
    className: 'is-online'
  },
  degraded: {
    label: strings.statusDegraded || 'Degraded',
    className: 'is-degraded'
  },
  offline: {
    label: strings.statusOffline || 'Offline',
    className: 'is-offline'
  }
})

export default function StatusBadge({ status, strings = {} }) {
  const normalized = String(status || '').toLowerCase()
  const meta = STATUS_META(strings)[normalized] || STATUS_META(strings).offline
  return (
    <span className={`mumb-health-status ${meta.className}`}>
      <span className='mumb-health-status__dot' />
      {meta.label}
    </span>
  )
}
