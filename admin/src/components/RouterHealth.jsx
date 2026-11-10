import React from 'react'
import { useRest } from '../hooks/useRest.js'
import { ToastContext } from '../toast.jsx'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

export default function RouterHealth(){
  const { base, nonce } = useRest()
  const addToast = React.useContext(ToastContext)
  const [loading,setLoading] = React.useState(true)
  const [data,setData] = React.useState(null)
  const [error,setError] = React.useState('')

  const fetchHealth = React.useCallback(() => {
    setLoading(true)
    fetch(base + '/router/health', { headers: { 'X-WP-Nonce': nonce } })
      .then(async (resp) => {
        let payload = null
        try { payload = await resp.json() } catch (err) { /* ignore */ }
        if (!resp.ok || !payload || !payload.success) {
          const message = (payload && (payload.message || payload.error)) || ('HTTP ' + resp.status)
          setError(message)
          addToast('error', message)
          return
        }
        setData(payload.data || null)
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
    fetchHealth()
  }, [fetchHealth])
  useRefreshRegistration(fetchHealth)

  const renderRest = () => {
    if (!data || !data.rest) return null
    const rest = data.rest
    return (
      <div style={{marginBottom:16}}>
        <h4>REST API</h4>
        <ul className='mwp-list'>
          <li><strong>Configured:</strong> {rest.configured ? 'Yes' : 'No'}</li>
          <li><strong>Reachable:</strong> {rest.reachable === null ? 'Unknown' : rest.reachable ? 'Yes' : 'No'}</li>
          <li><strong>User-profile endpoint:</strong> {rest.user_profile === null ? 'Unknown' : rest.user_profile ? 'Yes' : 'No'}</li>
          {rest.message ? <li><strong>Message:</strong> {rest.message}</li> : null}
        </ul>
      </div>
    )
  }

  const renderBinary = () => {
    if (!data || !data.binary) return null
    return (
      <div style={{marginBottom:16}}>
        <h4>RouterOS Binary API</h4>
        <ul className='mwp-list'>
          <li><strong>Reachable:</strong> {data.binary.reachable ? 'Yes' : 'No'}</li>
          {data.binary.message ? <li><strong>Message:</strong> {data.binary.message}</li> : null}
        </ul>
      </div>
    )
  }

  const renderErrors = () => {
    if (!data || !Array.isArray(data.last_errors) || data.last_errors.length === 0) {
      return null
    }
    return (
      <div style={{marginTop:16}}>
        <h4>Recent Errors</h4>
        <ul className='mwp-list'>
          {data.last_errors.map((log, index) => (
            <li key={index}>
              <div><strong>{log.time}</strong> [{log.level}] {log.action}</div>
              <div>{log.message}</div>
            </li>
          ))}
        </ul>
      </div>
    )
  }

  return (
      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>Router Health</div>
        <div style={{display:'flex',gap:12,alignItems:'center',marginBottom:12}}>
          {error ? <span className='mwp-error'>{error}</span> : null}
        </div>
      {loading ? <p className='mwp-loading'>Checking router…</p> : null}
      {!loading && data ? (
        <div>
          <div style={{marginBottom:16}}>
            <h4>Overview</h4>
            <ul className='mwp-list'>
              <li><strong>Configured:</strong> {data.configured ? 'Yes' : 'No'}</li>
              <li><strong>Master mode:</strong> {data.master_mode ? 'Enabled' : 'Disabled'}</li>
              <li><strong>Dual namespace:</strong> {data.capabilities?.dual_namespace ? 'Yes' : 'Unknown'}</li>
            </ul>
          </div>
          {renderBinary()}
          {renderRest()}
        </div>
      ) : null}
      {renderErrors()}
    </div>
  )
}
