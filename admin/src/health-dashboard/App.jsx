import React from 'react'
import StatusBadge from './components/StatusBadge.jsx'
import TrendChart from './components/TrendChart.jsx'
import './styles.css'

const HISTORY_LIMIT = 10

function formatNumber(value) {
  if (value === null || value === undefined || value === '') {
    return '—'
  }
  const number = Number(value)
  if (Number.isNaN(number)) {
    return '—'
  }
  if (Math.abs(number) >= 1000) {
    return number.toLocaleString()
  }
  return number.toString()
}

function formatLatency(value) {
  if (value === null || value === undefined) {
    return '—'
  }
  const number = Number(value)
  if (Number.isNaN(number)) {
    return '—'
  }
  return `${number} ms`
}

function formatPercent(value) {
  if (value === null || value === undefined) {
    return '—'
  }
  const number = Number(value)
  if (Number.isNaN(number)) {
    return '—'
  }
  const rounded = Math.round(number * 10) / 10
  return `${rounded}%`
}

function formatBandwidth(value) {
  if (value === null || value === undefined) {
    return '—'
  }
  const number = Number(value)
  if (Number.isNaN(number) || number <= 0) {
    return '—'
  }
  if (number >= 1_000_000_000) {
    return `${(number / 1_000_000_000).toFixed(2)} Gbps`
  }
  if (number >= 1_000_000) {
    return `${(number / 1_000_000).toFixed(2)} Mbps`
  }
  if (number >= 1_000) {
    return `${(number / 1_000).toFixed(2)} Kbps`
  }
  return `${number} bps`
}

function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) {
    return '—'
  }
  const total = Number(seconds)
  if (!Number.isFinite(total) || total < 0) {
    return '—'
  }
  const days = Math.floor(total / 86400)
  const hours = Math.floor((total % 86400) / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const parts = []
  if (days) {
    parts.push(`${days}d`)
  }
  if (hours) {
    parts.push(`${hours}h`)
  }
  if (minutes && parts.length < 2) {
    parts.push(`${minutes}m`)
  }
  if (!parts.length) {
    parts.push(`${Math.floor(total)}s`)
  }
  return parts.join(' ')
}

function parseTimestamp(value) {
  if (!value) {
    return null
  }
  const iso = value.endsWith('Z') || value.includes('T') ? value : `${value}Z`
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) {
    return null
  }
  return date
}

function formatDateTime(value) {
  const date = parseTimestamp(value)
  if (!date) {
    return '—'
  }
  return date.toLocaleString()
}

export default function App({ config }) {
  const strings = config.strings || {}
  const restBase = config.restBase || '/wp-json/mikrotik/v1'
  const nonce = config.nonce || ''
  const pollInterval = typeof config.pollInterval === 'number' && config.pollInterval > 0 ? config.pollInterval : 60000

  const [servers, setServers] = React.useState([])
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState('')
  const [filters, setFilters] = React.useState({ role: '', status: '', search: '' })
  const [sort, setSort] = React.useState({ key: 'name', direction: 'asc' })
  const [selectedId, setSelectedId] = React.useState(null)
  const [history, setHistory] = React.useState([])
  const [refreshingAll, setRefreshingAll] = React.useState(false)
  const [refreshingMap, setRefreshingMap] = React.useState({})
  const [lastUpdated, setLastUpdated] = React.useState(null)

  const buildHeaders = React.useCallback((method = 'GET') => {
    const headers = new Headers({ 'Accept': 'application/json' })
    if (nonce) {
      headers.set('X-WP-Nonce', nonce)
    }
    if (method !== 'GET') {
      headers.set('Content-Type', 'application/json')
    }
    return headers
  }, [nonce])

  const fetchServers = React.useCallback(async ({ silent = false } = {}) => {
    if (!silent) {
      setLoading(true)
    }
    setError('')
    try {
      const params = new URLSearchParams()
      if (filters.role) {
        params.set('role', filters.role)
      }
      if (filters.status) {
        params.set('status', filters.status)
      }
      const url = `${restBase}/servers/status${params.toString() ? `?${params.toString()}` : ''}`
      const response = await fetch(url, { headers: buildHeaders(), credentials: 'same-origin' })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      const payload = await response.json()
      const list = Array.isArray(payload?.servers) ? payload.servers : []
      setServers(list)
      if (!selectedId && list.length) {
        setSelectedId(list[0].id)
      } else if (selectedId && list.every((server) => server.id !== selectedId)) {
        setSelectedId(list.length ? list[0].id : null)
      }
      setLastUpdated(new Date())
    } catch (err) {
      setError(err?.message || strings.error || 'Request failed')
    } finally {
      if (!silent) {
        setLoading(false)
      }
    }
  }, [buildHeaders, filters.role, filters.status, restBase, selectedId, strings.error])

  const fetchHistory = React.useCallback(async (serverId) => {
    if (!serverId) {
      setHistory([])
      return
    }
    try {
      const params = new URLSearchParams({ server_id: String(serverId), limit: String(HISTORY_LIMIT) })
      const response = await fetch(`${restBase}/servers/history?${params.toString()}`, {
        headers: buildHeaders(),
        credentials: 'same-origin'
      })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      const payload = await response.json()
      const entries = Array.isArray(payload?.entries) ? payload.entries : []
      setHistory(entries)
    } catch (err) {
      // history is optional; surface as console warning only
      console.warn('[MUMB] Failed to load server history', err)
      setHistory([])
    }
  }, [buildHeaders, restBase])

  React.useEffect(() => {
    fetchServers()
  }, [fetchServers])

  React.useEffect(() => {
    if (!selectedId) {
      setHistory([])
      return
    }
    fetchHistory(selectedId)
  }, [selectedId, fetchHistory])

  React.useEffect(() => {
    if (!pollInterval) {
      return undefined
    }
    const id = window.setInterval(() => {
      fetchServers({ silent: true })
      if (selectedId) {
        fetchHistory(selectedId)
      }
    }, pollInterval)
    return () => window.clearInterval(id)
  }, [pollInterval, fetchServers, fetchHistory, selectedId])

  const handleSort = React.useCallback((key) => {
    setSort((prev) => {
      if (prev.key === key) {
        return { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' }
      }
      return { key, direction: 'asc' }
    })
  }, [])

  const filteredServers = React.useMemo(() => {
    const search = filters.search.trim().toLowerCase()
    let list = [...servers]
    if (search) {
      list = list.filter((server) => {
        const haystack = `${server.name || ''} ${server.hostname || ''}`.toLowerCase()
        return haystack.includes(search)
      })
    }
    const { key, direction } = sort
    const multiplier = direction === 'desc' ? -1 : 1
    list.sort((a, b) => {
      const av = a[key]
      const bv = b[key]
      if (av === bv) {
        return 0
      }
      if (av === null || av === undefined) {
        return 1 * multiplier
      }
      if (bv === null || bv === undefined) {
        return -1 * multiplier
      }
      if (typeof av === 'number' && typeof bv === 'number') {
        return av > bv ? 1 * multiplier : -1 * multiplier
      }
      return String(av).localeCompare(String(bv)) * multiplier
    })
    return list
  }, [filters.search, servers, sort])

  const selectedServer = React.useMemo(() => {
    if (!selectedId) {
      return null
    }
    return servers.find((server) => server.id === selectedId) || null
  }, [selectedId, servers])

  const handleSelectServer = React.useCallback((serverId) => {
    setSelectedId(serverId)
  }, [])

  const handleRefreshAll = React.useCallback(async () => {
    setRefreshingAll(true)
    try {
      const response = await fetch(`${restBase}/servers/refresh`, {
        method: 'POST',
        headers: buildHeaders('POST'),
        body: '{}',
        credentials: 'same-origin'
      })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      await fetchServers({ silent: true })
      if (selectedId) {
        await fetchHistory(selectedId)
      }
    } catch (err) {
      setError(err?.message || strings.error || 'Request failed')
    } finally {
      setRefreshingAll(false)
    }
  }, [buildHeaders, fetchHistory, fetchServers, restBase, selectedId, strings.error])

  const handleRefreshServer = React.useCallback(async (serverId) => {
    setRefreshingMap((prev) => ({ ...prev, [serverId]: true }))
    try {
      const response = await fetch(`${restBase}/servers/${serverId}/refresh`, {
        method: 'POST',
        headers: buildHeaders('POST'),
        body: '{}',
        credentials: 'same-origin'
      })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      await fetchServers({ silent: true })
      if (selectedId === serverId) {
        await fetchHistory(serverId)
      }
    } catch (err) {
      setError(err?.message || strings.error || 'Request failed')
    } finally {
      setRefreshingMap((prev) => {
        const next = { ...prev }
        delete next[serverId]
        return next
      })
    }
  }, [buildHeaders, fetchHistory, fetchServers, restBase, selectedId, strings.error])

  const latencySeries = React.useMemo(() => {
    if (!history.length) {
      return []
    }
    return [...history].reverse().map((entry) => {
      const date = parseTimestamp(entry.recorded_at)
      return {
        timestamp: entry.recorded_at,
        label: date ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : entry.recorded_at,
        latency: entry.latency_ms ?? 0
      }
    })
  }, [history])

  const activeSeries = React.useMemo(() => {
    if (!history.length) {
      return []
    }
    return [...history].reverse().map((entry) => {
      const date = parseTimestamp(entry.recorded_at)
      return {
        timestamp: entry.recorded_at,
        label: date ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : entry.recorded_at,
        active: entry.active_users ?? 0
      }
    })
  }, [history])

  const lastUpdatedLabel = React.useMemo(() => {
    if (!lastUpdated) {
      return null
    }
    const formatted = lastUpdated.toLocaleTimeString()
    if (strings.lastUpdatedAt) {
      return strings.lastUpdatedAt.replace('%s', formatted)
    }
    return `Last updated ${formatted}`
  }, [lastUpdated, strings.lastUpdatedAt])

  const renderTable = () => {
    if (loading) {
      return (
        <div className='mumb-health-card__body'>
          <span className='spinner is-active' />
        </div>
      )
    }
    if (error) {
      return (
        <div className='mumb-health-card__body'>
          <p className='mumb-health-error'>{error}</p>
        </div>
      )
    }
    if (!filteredServers.length) {
      return (
        <div className='mumb-health-card__body'>
          <p className='mumb-health-helper'>{strings.noData || 'No servers found.'}</p>
        </div>
      )
    }
    const sortableClass = (key) => {
      const classes = ['sortable']
      if (sort.key === key) {
        classes.push('is-active')
        if (sort.direction === 'desc') {
          classes.push('is-desc')
        }
      }
      return classes.join(' ')
    }
    return (
      <div className='mumb-health-card__body'>
        <table className='mumb-health-table'>
          <thead>
            <tr>
              <th className={sortableClass('name')} onClick={() => handleSort('name')}>{strings.name || 'Name'}</th>
              <th className={sortableClass('hostname')} onClick={() => handleSort('hostname')}>{strings.hostname || 'Hostname'}</th>
              <th>{strings.role || 'Role'}</th>
              <th className={sortableClass('status')} onClick={() => handleSort('status')}>{strings.status || 'Status'}</th>
              <th className={sortableClass('latency_ms')} onClick={() => handleSort('latency_ms')}>{strings.latency || 'Latency'}</th>
              <th className={sortableClass('active_users')} onClick={() => handleSort('active_users')}>{strings.activeUsers || 'Active users'}</th>
              <th className={sortableClass('last_ping')} onClick={() => handleSort('last_ping')}>{strings.lastChecked || 'Last checked'}</th>
              <th>{strings.actions || 'Actions'}</th>
            </tr>
          </thead>
          <tbody>
            {filteredServers.map((server) => {
              const isSelected = server.id === selectedId
              return (
                <tr
                  key={server.id}
                  className={isSelected ? 'is-selected' : ''}
                  onClick={() => handleSelectServer(server.id)}
                >
                  <td>{server.name || server.hostname || '—'}</td>
                  <td>{server.hostname || '—'}</td>
                  <td>{server.role || '—'}</td>
                  <td><StatusBadge status={server.status} strings={strings} /></td>
                  <td>{formatLatency(server.latency_ms)}</td>
                  <td>{formatNumber(server.active_users)}</td>
                  <td>{formatDateTime(server.last_ping)}</td>
                  <td className='mumb-health-actions-cell'>
                    <button
                      type='button'
                      onClick={(event) => {
                        event.stopPropagation()
                        handleRefreshServer(server.id)
                      }}
                      disabled={Boolean(refreshingMap[server.id]) || refreshingAll}
                    >
                      {refreshingMap[server.id] ? (
                        <span className='spinner is-active' style={{ margin: 0 }} />
                      ) : (
                        strings.btnRefreshRow || 'Refresh'
                      )}
                    </button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    )
  }

  const renderDetails = () => {
    if (!selectedServer) {
      return (
        <div className='mumb-health-card__body'>
          <div className='mumb-health-empty-state'>
            <p>{strings.selectPrompt || 'Select a server to view detailed metrics.'}</p>
          </div>
        </div>
      )
    }
    return (
      <div className='mumb-health-card__body mumb-health-detail'>
        <div className='mumb-health-detail__header'>
          <h2 className='mumb-health-detail__title'>{selectedServer.name || selectedServer.hostname || '—'}</h2>
          <p className='mumb-health-detail__subtitle'>{selectedServer.hostname || '—'}</p>
          {lastUpdatedLabel ? <span className='mumb-health-timestamp'>{lastUpdatedLabel}</span> : null}
        </div>
        <p className='mumb-health-helper'>{strings.detailsTitle || 'Server details'}</p>
        <div className='mumb-health-meta'>
          <div className='mumb-health-meta__item'>
            <span className='mumb-health-meta__label'>{strings.latency || 'Latency'}</span>
            <span className='mumb-health-meta__value'>{formatLatency(selectedServer.latency_ms)}</span>
          </div>
          <div className='mumb-health-meta__item'>
            <span className='mumb-health-meta__label'>{strings.activeUsers || 'Active users'}</span>
            <span className='mumb-health-meta__value'>{formatNumber(selectedServer.active_users)}</span>
          </div>
          <div className='mumb-health-meta__item'>
            <span className='mumb-health-meta__label'>{strings.uptime || 'Uptime'}</span>
            <span className='mumb-health-meta__value'>{formatDuration(selectedServer.uptime_seconds)}</span>
          </div>
          <div className='mumb-health-meta__item'>
            <span className='mumb-health-meta__label'>{strings.cpuLoad || 'CPU load'}</span>
            <span className='mumb-health-meta__value'>{formatPercent(selectedServer.cpu_load)}</span>
          </div>
          <div className='mumb-health-meta__item'>
            <span className='mumb-health-meta__label'>{strings.memoryUsage || 'Memory usage'}</span>
            <span className='mumb-health-meta__value'>{formatPercent(selectedServer.memory_usage)}</span>
          </div>
          <div className='mumb-health-meta__item'>
            <span className='mumb-health-meta__label'>{strings.bandwidth || 'Bandwidth'}</span>
            <span className='mumb-health-meta__value'>{formatBandwidth(selectedServer.bandwidth_bps)}</span>
          </div>
        </div>
        <div className='mumb-health-history'>
          {latencySeries.length ? (
            <TrendChart
              data={latencySeries}
              dataKey='latency'
              color='#2563eb'
              label={strings.chartLatency || 'Latency trend'}
              valueFormatter={(value) => `${value} ms`}
            />
          ) : <p className='mumb-health-history-empty'>{strings.historyEmpty || 'No recent history available.'}</p>}
          {activeSeries.length ? (
            <TrendChart
              data={activeSeries}
              dataKey='active'
              color='#16a34a'
              label={strings.chartActive || 'Active users trend'}
              valueFormatter={(value) => value}
            />
          ) : null}
        </div>
      </div>
    )
  }

  return (
    <div className='mumb-health-dashboard'>
      <div className='mumb-health-header'>
        <h1>{strings.title || 'Health Dashboard'}</h1>
        <div className='mumb-health-actions'>
          <button
            type='button'
            className='mumb-health-refresh'
            onClick={handleRefreshAll}
            disabled={refreshingAll}
          >
            {refreshingAll ? (
              <span className='spinner is-active' style={{ margin: 0 }} />
            ) : null}
            {strings.btnRefreshAll || 'Refresh Now'}
          </button>
        </div>
      </div>
      <div className='mumb-health-filter-bar'>
        <input
          type='search'
          placeholder={strings.filterSearch || 'Search servers…'}
          value={filters.search}
          onChange={(event) => setFilters((prev) => ({ ...prev, search: event.target.value }))}
        />
        <select value={filters.role} onChange={(event) => setFilters((prev) => ({ ...prev, role: event.target.value }))}>
          <option value=''>{strings.allRoles || 'All roles'}</option>
          <option value='usermanager'>User Manager</option>
          <option value='vpnnode'>VPN Node</option>
        </select>
        <select value={filters.status} onChange={(event) => setFilters((prev) => ({ ...prev, status: event.target.value }))}>
          <option value=''>{strings.allStatuses || 'All statuses'}</option>
          <option value='online'>{strings.statusOnline || 'Online'}</option>
          <option value='degraded'>{strings.statusDegraded || 'Degraded'}</option>
          <option value='offline'>{strings.statusOffline || 'Offline'}</option>
        </select>
      </div>
      <div className='mumb-health-layout'>
        <div className='mumb-health-card'>
          {renderTable()}
        </div>
        <div className='mumb-health-card'>
          {renderDetails()}
        </div>
      </div>
    </div>
  )
}
