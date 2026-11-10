import React from 'react'
import { useRest } from '../hooks/useRest.js'
import { ToastContext } from '../toast.jsx'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

function formatTimestamp(value){
  if (!value) return '—'
  const ts = typeof value === 'number' ? value * 1000 : Date.parse(value)
  if (Number.isNaN(ts)) return value
  const date = new Date(ts)
  return date.toLocaleString()
}

export default function QueuePanel(){
  const { base, nonce } = useRest()
  const addToast = React.useContext(ToastContext)
  const [loading,setLoading] = React.useState(true)
  const [pending,setPending] = React.useState([])
  const [history,setHistory] = React.useState([])
  const [error,setError] = React.useState('')

  const fetchQueue = React.useCallback(() => {
    setLoading(true)
    fetch(base + '/queue', { headers: { 'X-WP-Nonce': nonce } })
      .then(async (resp) => {
        let payload = null
        try { payload = await resp.json() } catch (err) { /* ignore */ }
        if (!resp.ok || !payload || !payload.success) {
          const message = (payload && (payload.message || payload.error)) || ('HTTP ' + resp.status)
          setError(message)
          addToast('error', message)
          return
        }
        const data = payload.data || {}
        setPending(Array.isArray(data.pending) ? data.pending : [])
        setHistory(Array.isArray(data.history) ? data.history : [])
        setError('')
      })
      .catch((err) => {
        const message = err && err.message ? err.message : 'Request failed'
        setError(message)
        addToast('error', message)
      })
      .finally(() => setLoading(false))
  }, [addToast, base, nonce])

  React.useEffect(() => {
    fetchQueue()
  }, [fetchQueue])
  useRefreshRegistration(fetchQueue)

  const retry = React.useCallback((key, fromHistory = false) => {
    fetch(base + '/queue/' + encodeURIComponent(key) + '/retry' + (fromHistory ? '?from_history=1' : ''), {
      method: 'POST',
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
    }).then(async (resp) => {
      let payload = null
      try { payload = await resp.json() } catch (err) { /* ignore */ }
      if (!resp.ok || !payload || !payload.success) {
        const message = (payload && (payload.message || payload.error)) || ('HTTP ' + resp.status)
        addToast('error', message)
        return
      }
      addToast('success', fromHistory ? 'Job requeued from history' : 'Job scheduled for retry')
      fetchQueue()
    }).catch((err) => {
      addToast('error', err && err.message ? err.message : 'Retry failed')
    })
  }, [addToast, base, nonce, fetchQueue])

  return (
    <div>
      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>Queue</div>
        <div style={{display:'flex',gap:12,alignItems:'center',marginBottom:12}}>
          {error ? <span className='mwp-error'>{error}</span> : null}
        </div>
        {loading ? <p className='mwp-loading'>Loading queue…</p> : null}
        {!loading && pending.length === 0 ? <p className='mwp-muted'>No pending jobs.</p> : null}
        {pending.length > 0 ? (
          <table className='mwp-table'>
            <thead>
              <tr>
                <th>Key</th>
                <th>Type</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Next run</th>
                <th>Last error</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {pending.map((job) => (
                <tr key={job.key}>
                  <td>{job.key}</td>
                  <td>{job.type || '—'}</td>
                  <td>{job.status || 'pending'}</td>
                  <td>{job.attempts || 0}</td>
                  <td>{formatTimestamp(job.next_run)}</td>
                  <td>{job.last_error || '—'}</td>
                  <td>
                    <button type='button' className='mwp-btn mwp-btn-outline' onClick={() => retry(job.key)} disabled={loading}>Retry</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>

      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>Recent History</div>
        {history.length === 0 ? <p className='mwp-muted'>No history captured yet.</p> : null}
        {history.length > 0 ? (
          <table className='mwp-table'>
            <thead>
              <tr>
                <th>Time</th>
                <th>Key</th>
                <th>Type</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Message</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {history.map((entry) => (
                <tr key={entry.key + entry.time}>
                  <td>{formatTimestamp(entry.time)}</td>
                  <td>{entry.key}</td>
                  <td>{entry.type || '—'}</td>
                  <td>{entry.status}</td>
                  <td>{entry.attempts || 0}</td>
                  <td>{entry.message || '—'}</td>
                  <td>
                    <button type='button' className='mwp-btn mwp-btn-outline' onClick={() => retry(entry.key, true)}>Requeue</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
    </div>
  )
}
