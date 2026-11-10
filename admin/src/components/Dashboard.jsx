import React from 'react'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

function useRest(){
  const bootstrap = (typeof window !== 'undefined' && window.MWP_BOOTSTRAP) ? window.MWP_BOOTSTRAP : {}
  const base = (bootstrap.rest && (bootstrap.rest.mwpBase || bootstrap.rest.mikrotikBase)) || '/wp-json/mwp/v1'
  const nonce = (bootstrap.rest && bootstrap.rest.nonce) || ''
  return { base, nonce }
}

export default function Dashboard(){
  const { base, nonce } = useRest()
  const [health, setHealth] = React.useState(null)
  const [loading, setLoading] = React.useState(false)
  const [error, setError] = React.useState('')

  const load = React.useCallback(function(){
    setLoading(true)
    setError('')
    fetch(base + '/health', { headers:{ 'X-WP-Nonce': nonce } })
      .then(function(r){ return r.json() })
      .then(function(payload){
        const h = (payload && payload.data) || {}
        setHealth(h)
      }).catch(function(fetchError){
      setError(fetchError && fetchError.message ? fetchError.message : 'Failed to load status')
    }).finally(function(){ setLoading(false) })
  }, [base, nonce])

  React.useEffect(function(){ load() }, [load])
  useRefreshRegistration(load)

  const connected = health && typeof health.connected !== 'undefined' ? !!health.connected : null
  const statusBadge = connected === null ? 'mwp-badge-muted' : connected ? 'mwp-badge-success' : 'mwp-badge-error'
  const statusLabel = connected === null ? 'Unknown' : connected ? 'Connected' : 'Disconnected'

  return (
    <div>
      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>Router Status</div>
        {loading ? <p className='mwp-loading'>Loading status…</p> : null}
        {error ? <p className='mwp-error'>{error}</p> : null}
        {!loading && !error ? (
          <div className='mwp-grid-2'>
            <div className='mwp-cell'>
              <span className='mwp-cell-sub'>Connection</span>
              <span className={`mwp-badge ${statusBadge}`}>{statusLabel}</span>
              <span className='mwp-cell-sub'>Mirror authority: {(health && health.settings && health.settings.mirror_authority) || 'router'}</span>
            </div>
            <div className='mwp-cell'>
              <span className='mwp-cell-sub'>Router</span>
              <span className='mwp-cell-main'>{(health && health.server && health.server.identity) || '—'}</span>
              <span className='mwp-cell-sub'>v{(health && health.server && health.server.version) || '—'} · {(health && health.server && health.server.latency_ms) || 0} ms</span>
            </div>
            <div className='mwp-cell'>
              <span className='mwp-cell-sub'>Last Sync</span>
              <span className='mwp-cell-main'>{(health && health.last_sync) || '—'}</span>
              <span className='mwp-cell-sub'>{(health && health.last_sync_ms) || 0} ms</span>
            </div>
            <div className='mwp-cell'>
              <span className='mwp-cell-sub'>Pending Queue</span>
              <span className='mwp-cell-main'>{(health && health.queue && health.queue.pending) || 0}</span>
              <span className='mwp-cell-sub'>History {(health && health.queue && health.queue.history) || 0}</span>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}
