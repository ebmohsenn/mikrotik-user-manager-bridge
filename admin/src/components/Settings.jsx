import React from 'react'
import { ToastContext } from '../toast.jsx'
import { useRest } from '../hooks/useRest.js'
import { translate, sprintf } from '@mwp/utils/i18n'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'
import SyncDiffOverview from './SyncDiffOverview.jsx'

const __ = (text, fallback = text) => translate(text, fallback)

export default function Settings(){
  const addToast = React.useContext(ToastContext)
  const { base, nonce } = useRest()
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce, 'Content-Type':'application/json' }), [nonce])
  const authHeaders = React.useMemo(() => ({ 'X-WP-Nonce': nonce }), [nonce])
  const showToast = React.useCallback((type, message) => addToast(type, message), [addToast])
  const [data,setData] = React.useState({ settings:{}, state:{} })
  const [form,setForm] = React.useState({ host:'',port:8728,username:'',password:'',ssl:false,verify_ssl:false })
  const [bootstrapState,setBootstrapState] = React.useState({ running:false, mode:null, last:null, error:null })
  const [profileMap,setProfileMap] = React.useState('')
  const [savingProfileMap,setSavingProfileMap] = React.useState(false)
  const isMountedRef = React.useRef(true)
  const diffRefreshRef = React.useRef(null)
  React.useEffect(() => () => { isMountedRef.current = false }, [])
  const registerDiffRefresh = React.useCallback((handler) => {
    diffRefreshRef.current = typeof handler === 'function' ? handler : null
  }, [])

  const applySettingsPayload = React.useCallback((payload = {}) => {
    if ( !isMountedRef.current ) {
      return
    }
    setData(payload)
    const s = payload.settings || {}
    setForm(function(prev){
      return Object.assign({}, prev, {
        host: s.host || prev.host || '',
        port: typeof s.port === 'number' ? s.port : prev.port,
        username: s.username || prev.username || '',
        password: '',
        ssl: !!s.ssl,
        verify_ssl: !!s.verify_ssl,
      })
    })
    const map = payload.profile_product_map || {}
    try {
      // If map is already a string, try to parse it first to validate
      if (typeof map === 'string') {
        const parsed = JSON.parse(map)
        setProfileMap(JSON.stringify(parsed, null, 2))
      } else {
        setProfileMap(JSON.stringify(map, null, 2))
      }
    } catch (error) {
      console.warn('[Settings] Invalid profile_product_map from server:', map, error)
      setProfileMap('{}')
    }
  }, [])

  const fetchSettings = React.useCallback(() => {
    fetch(base + '/settings', { headers: authHeaders })
      .then(function(r){ return r.json() })
      .then(function(d){
        applySettingsPayload((d && d.data) || {})
      })
      .catch(function(error){
        if (!isMountedRef.current) {
          return
        }
        const fallback = __('Failed to load settings')
        addToast('error', error && error.message ? error.message : fallback)
      })
  }, [addToast, applySettingsPayload, authHeaders, base])

  React.useEffect(() => { fetchSettings() }, [fetchSettings])
  useRefreshRegistration(React.useCallback(() => {
    fetchSettings()
    if (typeof diffRefreshRef.current === 'function') {
      diffRefreshRef.current()
    }
  }, [fetchSettings]))
  function save(e){ e.preventDefault();
    let parsedMap = {}
    try {
      const trimmed = (profileMap || '').trim()
      parsedMap = trimmed ? JSON.parse(trimmed) : {}
    } catch (error) {
      console.error('[Settings] JSON parse error:', error, 'Raw value:', profileMap)
      showToast('error', __('Profile mapping must be valid JSON (e.g. {"Basic":123})') + ' Error: ' + (error.message || error))
      return
    }
    const payload = Object.assign({}, form, { profile_product_map: parsedMap })
    console.log('[Settings] Sending payload:', payload)
    fetch(base + '/settings', { method:'POST', headers, body: JSON.stringify(payload) }).then(async function(r){
      var j=null; try{ j = await r.json() }catch(error){ console.warn('Failed to parse settings response', error) }
      console.log('[Settings] Response status:', r.status, 'Body:', j)
      if(r.ok && j && j.success){
        showToast('success', __('Settings saved'))
        fetchSettings()
      }
      else { 
        const errorMsg = (j && (j.message||j.error)) || sprintf(__('HTTP %d'), r.status)
        console.error('[Settings] Save failed:', errorMsg, 'Full response:', j)
        addToast('error', errorMsg) 
      }
    }).catch(function(e2){
      const fallback = __('Error')
      console.error('[Settings] Network error:', e2)
      addToast('error', e2 && e2.message ? e2.message : fallback)
    })
  }
  function test(){ fetch(base + '/ping', { headers: { 'X-WP-Nonce': nonce } }).then(function(r){ return r.json() }).then(function(j){ if(j && j.success){ addToast('success', sprintf(__('Latency: %dms'), j.latency_ms || 0)) } else { addToast('error', (j && j.message) || __('Ping failed')) } }).catch(function(e){ const fallback = __('Error'); addToast('error', e && e.message ? e.message : fallback) }) }
  function disconnect(){
    if (!confirm(__('Disconnect from MikroTik server?'))) {
      return
    }
    fetch(base + '/disconnect', { method:'POST', headers: authHeaders })
      .then(function(r){ return r.json() })
      .then(function(j){
        if (j && j.success) {
          showToast('success', __('Disconnected'))
          fetchSettings()
        } else {
          addToast('error', (j && j.message) || __('Failed'))
        }
      })
      .catch(function(error){
        const fallback = __('Disconnect failed')
        addToast('error', error && error.message ? error.message : fallback)
      })
  }
  const connected = !!(data && data.state && data.state.connected)
  const disabled = connected
  const runBootstrap = React.useCallback(function(mode){
    const nextMode = mode === 'commit' ? 'commit' : 'dry-run'
    setBootstrapState(function(prev){
      return {
        running: true,
        mode: nextMode,
        last: nextMode === 'commit' ? prev.last : null,
        error: null
      }
    })
    fetch(base + '/bootstrap-sync?mode=' + encodeURIComponent(nextMode), { method:'POST', headers: { 'X-WP-Nonce': nonce } })
      .then(async function(r){
        let payload = null
        try { payload = await r.json() } catch (error) { /* ignore */ }
        if (!r.ok || !payload || !payload.success) {
          const message = (payload && (payload.message || payload.error)) || ('HTTP ' + r.status)
          setBootstrapState(function(prev){ return { running:false, mode: nextMode, last: prev.last, error: message } })
          addToast('error', message)
          return
        }
        const result = payload.data || payload
        if (result && result.mode === 'dry_run') { result.mode = 'dry-run' }
        setBootstrapState({ running:false, mode: nextMode, last: result, error:null })
        fetchSettings()
        addToast('success', nextMode === 'commit' ? __('Initial sync committed') : __('Preview ready'))
      })
      .catch(function(error){
        const message = error && error.message ? error.message : __('Request failed')
        setBootstrapState(function(prev){ return { running:false, mode: nextMode, last: prev.last, error: message } })
        addToast('error', message)
      })
  }, [addToast, base, fetchSettings, nonce])

  const saveProfileMapping = React.useCallback(function(event){
    event.preventDefault()
    let parsed = {}
    try {
      parsed = profileMap ? JSON.parse(profileMap) : {}
    } catch (error) {
      showToast('error', __('Profile mapping must be valid JSON (e.g. {"Basic":123})'))
      return
    }
    setSavingProfileMap(true)
    fetch(base + '/settings', { method:'POST', headers, body: JSON.stringify({ profile_product_map: parsed }) })
      .then(async function(resp){
        let payload = null
        try { payload = await resp.json() } catch (error) { /* ignore */ }
        if (resp.ok && payload && payload.success) {
          showToast('success', __('Profile mapping saved'))
          fetchSettings()
        } else {
          const message = (payload && (payload.message || payload.error)) || ('HTTP ' + resp.status)
          addToast('error', message)
        }
      })
      .catch(function(error){
        addToast('error', error && error.message ? error.message : __('Request failed'))
      })
      .finally(function(){ setSavingProfileMap(false) })
  }, [addToast, base, fetchSettings, headers, profileMap, showToast])

  const canCommit = React.useMemo(function(){
    return !!(bootstrapState.last && bootstrapState.last.mode === 'dry-run')
  }, [bootstrapState.last])

  const formatDateTime = React.useCallback(function(value){
    if (!value) { return '—' }
    const ts = Date.parse(value)
    if (!Number.isNaN(ts)) {
      return new Date(ts).toLocaleString()
    }
    return typeof value === 'string' ? value : String(value)
  }, [])

  const dryRunInsights = React.useMemo(function(){
    const last = bootstrapState.last
    if (!last || last.mode !== 'dry-run' || !Array.isArray(last.users)) {
      return { conflicts: [], conflictsExtra: 0, expiries: [], expiriesExtra: 0 }
    }
    const users = last.users
    const conflictsAll = users.filter(function(user){ return Array.isArray(user.conflicts) && user.conflicts.length > 0 })
    const conflictItems = conflictsAll.slice(0, 10).map(function(user){
      const details = Array.isArray(user.conflicts) ? user.conflicts.join('; ') : ''
      return { username: user.username, details }
    })
    const expiriesAll = users.filter(function(user){ return user && user.expires_at }).map(function(user){
      return { username: user.username, expires_at: user.expires_at, status: user.status || '' }
    })
    expiriesAll.sort(function(a, b){
      const ta = Date.parse(a.expires_at || '')
      const tb = Date.parse(b.expires_at || '')
      if (Number.isNaN(ta) && Number.isNaN(tb)) return 0
      if (Number.isNaN(ta)) return 1
      if (Number.isNaN(tb)) return -1
      return ta - tb
    })
    const expiryItems = expiriesAll.slice(0, 10)
    return {
      conflicts: conflictItems,
      conflictsExtra: Math.max(0, conflictsAll.length - conflictItems.length),
      expiries: expiryItems,
      expiriesExtra: Math.max(0, expiriesAll.length - expiryItems.length)
    }
  }, [bootstrapState.last])

  const renderBootstrapSummary = React.useCallback(function(summary){
    if (!summary) { return null }
    const labels = {
      total_users: __('Total MikroTik users'),
      wp_users_existing: __('Existing WP users'),
      wp_users_missing: __('Users to create'),
      wp_users_conflicts: __('Conflicts detected'),
      subscriptions_needed: __('Subscriptions to reconcile'),
      active_users: __('Active users'),
      expired_users: __('Expired users')
    }
    return (
      <ul className='mwp-list'>
        {Object.keys(summary).map(function(key){
          const label = labels[key] || key
          const value = summary[key]
          return <li key={key}><strong>{label}:</strong> {typeof value === 'number' ? value : String(value)}</li>
        })}
      </ul>
    )
  }, [])
  return (
    <div>
      <h2>{__('Settings')}</h2>
      <form onSubmit={save} className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>{__('Connection Settings')}</div>
        <div className='mwp-form-grid'>
          <input className='mwp-input' placeholder={__('Host / IP')} value={form.host||''} onChange={function(e){ setForm(Object.assign({}, form, { host: e.target.value })) }} disabled={disabled} />
          <input className='mwp-input' type='number' placeholder={__('Port')} value={form.port||8728} onChange={function(e){ var v=parseInt(e.target.value||'8728',10); setForm(Object.assign({}, form, { port: v })) }} disabled={disabled} />
          <input className='mwp-input' placeholder={__('Username')} value={form.username||''} onChange={function(e){ setForm(Object.assign({}, form, { username: e.target.value })) }} disabled={disabled} />
          <input className='mwp-input' placeholder={__('Password (leave blank to keep)')} value={form.password||''} onChange={function(e){ setForm(Object.assign({}, form, { password: e.target.value })) }} disabled={disabled} />
          <label className='mwp-checkbox'>
            <input type='checkbox' checked={!!form.ssl} onChange={function(e){ setForm(Object.assign({}, form, { ssl: e.target.checked ? 1 : 0 })) }} disabled={disabled} /> {__('Use TLS/SSL')}
          </label>
          <label className='mwp-checkbox'>
            <input type='checkbox' checked={!!form.verify_ssl} onChange={function(e){ setForm(Object.assign({}, form, { verify_ssl: e.target.checked ? 1 : 0 })) }} disabled={disabled} /> {__('Verify TLS certificate')}
          </label>
          {!form.verify_ssl ? <p className='mwp-muted'>{__('Warning: Verification is disabled by default for self-signed MikroTik certificates.')}</p> : null}
          <div style={{display:'flex', gap:8}}>
            {!connected ? <button className='mwp-btn mwp-btn-primary'>{__('Save & Connect')}</button> : null}
          </div>
          {connected ? <span className='mwp-muted'>{__('Settings are locked while connected.')}</span> : null}
        </div>
      </form>
      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>{__('Connection Status')}</div>
        <div style={{display:'flex', flexWrap:'wrap', gap:12, alignItems:'center'}}>
          <span className={`mwp-badge ${connected ? 'mwp-badge-success' : 'mwp-badge-error'}`}>{connected ? __('Connected') : __('Disconnected')}</span>
          <button className='mwp-btn' onClick={test}>{__('Ping')}</button>
          {connected ? <button className='mwp-btn mwp-btn-danger' onClick={disconnect}>{__('Disconnect')}</button> : null}
          {data && data.state ? <span className='mwp-muted'>Last success: {data.state.last_success || '—'} | Last error: {data.state.last_error || '—'}</span> : null}
        </div>
      </div>
      {!connected ? (
        <div className='mwp-card mwp-form-card'>
          <div className='mwp-heading'>{__('Advanced')}</div>
          <div className='mwp-form-grid' style={{maxWidth:720}}>
            <input className='mwp-input' type='number' min='2' placeholder={__('API Timeout (sec)')} value={form.api_timeout||8} onChange={function(e){ var v=parseInt(e.target.value||'8',10); setForm(Object.assign({}, form, { api_timeout: v })) }} />
            <input className='mwp-input' type='number' min='0' placeholder={__('Retry Max')} value={form.retry_max||3} onChange={function(e){ var v=parseInt(e.target.value||'3',10); setForm(Object.assign({}, form, { retry_max: v })) }} />
            <input className='mwp-input' type='number' min='0' placeholder={__('Retry Base (ms)')} value={form.retry_base_ms||200} onChange={function(e){ var v=parseInt(e.target.value||'200',10); setForm(Object.assign({}, form, { retry_base_ms: v })) }} />
            <input className='mwp-input' type='number' min='0' placeholder={__('Retry Jitter (ms)')} value={form.retry_jitter_ms||100} onChange={function(e){ var v=parseInt(e.target.value||'100',10); setForm(Object.assign({}, form, { retry_jitter_ms: v })) }} />
            <input className='mwp-input' type='number' min='0' placeholder={__('Keepalive Jitter (ms)')} value={form.keepalive_jitter_ms||500} onChange={function(e){ var v=parseInt(e.target.value||'500',10); setForm(Object.assign({}, form, { keepalive_jitter_ms: v })) }} />
            <input className='mwp-input' type='number' min='0' placeholder={__('Keepalive Failure Threshold')} value={form.keepalive_failure_threshold||2} onChange={function(e){ var v=parseInt(e.target.value||'2',10); setForm(Object.assign({}, form, { keepalive_failure_threshold: v })) }} />
            <select className='mwp-select' value={form.mirror_authority||'router'} onChange={function(e){ setForm(Object.assign({}, form, { mirror_authority: e.target.value })) }}>
              <option value='router'>{__('Router wins')}</option>
              <option value='wp'>{__('WordPress wins')}</option>
            </select>
          </div>
        </div>
      ) : null}
      <SyncDiffOverview
        title={__('Sync Diff Overview')}
        footer={__('Review pending differences, then run the sync tools below to commit changes.')}
        registerRefresh={false}
        onRefreshRef={registerDiffRefresh}
      />
      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>{__('Initial Sync')}</div>
        <p className='mwp-muted'>{__('Preview or run the bootstrap synchronisation to import MikroTik users, groups, and profiles into WordPress.')}</p>
        <p className='mwp-muted'>
          {sprintf(
            __('Master mode: %s'),
            data && data.master_mode ? __('Enabled (WooCommerce controls users)') : __('Disabled (Router remains authoritative)')
          )}
        </p>
        <div style={{display:'flex', gap:12, flexWrap:'wrap', alignItems:'center'}}>
          <button type='button' className='mwp-btn' disabled={bootstrapState.running} onClick={function(){ runBootstrap('dry-run') }}>{__('Run Initial Sync')}</button>
          <button type='button' className='mwp-btn mwp-btn-primary' disabled={bootstrapState.running || !canCommit} onClick={function(){ runBootstrap('commit') }}>{__('Confirm & Commit')}</button>
          {!canCommit ? <span className='mwp-muted'>{__('Preview to enable commit.')}</span> : null}
        </div>
        {bootstrapState.running ? <p className='mwp-loading'>{__('Processing…')}</p> : null}
        {bootstrapState.error ? <p className='mwp-error'>{bootstrapState.error}</p> : null}
        {bootstrapState.last ? (
          <div className='mwp-card mwp-form-card' style={{marginTop:16}}>
            <div className='mwp-heading'>{__('Latest result')}</div>
            <p className='mwp-muted'>{sprintf(__('Mode: %s'), bootstrapState.last.mode || bootstrapState.mode)}</p>
            {renderBootstrapSummary(bootstrapState.last.summary)}
            {bootstrapState.last.mode === 'dry-run' ? (
              <div style={{marginTop:12, display:'grid', gap:16}}>
                <div>
                  <h4>{__('Conflicts')}</h4>
                  {dryRunInsights.conflicts.length ? (
                    <ul className='mwp-list'>
                      {dryRunInsights.conflicts.map(function(item){
                        return <li key={'conflict-' + item.username}><strong>{item.username}</strong>: {item.details || __('Conflict detected')}</li>
                      })}
                    </ul>
                  ) : <p className='mwp-muted'>{__('No conflicts detected.')}</p>}
                  {dryRunInsights.conflictsExtra ? (
                    <p className='mwp-muted'>
                      {dryRunInsights.conflictsExtra === 1
                        ? __('+ 1 additional conflict.')
                        : sprintf(__('+ %d additional conflicts.'), dryRunInsights.conflictsExtra)}
                    </p>
                  ) : null}
                </div>
                <div>
                  <h4>{__('Expiry Summary')}</h4>
                  {dryRunInsights.expiries.length ? (
                    <ul className='mwp-list'>
                      {dryRunInsights.expiries.map(function(item, index){
                        return <li key={'expiry-' + item.username + '-' + index}><strong>{item.username}</strong>: {formatDateTime(item.expires_at)}{item.status ? ` (${item.status})` : ''}</li>
                      })}
                    </ul>
                  ) : <p className='mwp-muted'>{__('No expiry data detected.')}</p>}
                  {dryRunInsights.expiriesExtra ? (
                    <p className='mwp-muted'>
                      {sprintf(
                        __('Showing first 10 of %d users with expiry data.'),
                        dryRunInsights.expiriesExtra + dryRunInsights.expiries.length
                      )}
                    </p>
                  ) : null}
                </div>
              </div>
            ) : null}
            {Array.isArray(bootstrapState.last.errors) && bootstrapState.last.errors.length ? (
              <details style={{marginTop:12}}>
                <summary>{sprintf(__('Errors (%d)'), bootstrapState.last.errors.length)}</summary>
                <ul className='mwp-list'>
                  {bootstrapState.last.errors.map(function(error, index){
                    if (typeof error === 'string') { return <li key={index}>{error}</li> }
                    if (error && typeof error === 'object') {
                      const label = [error.username, error.message].filter(Boolean).join(' — ')
                      return <li key={index}>{label || JSON.stringify(error)}</li>
                    }
                    return null
                  })}
                </ul>
              </details>
            ) : null}
          </div>
        ) : null}
      </div>

      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>{__('Profile Mapping')}</div>
        <p className='mwp-muted'>
          {__('Provide a JSON object mapping MikroTik profile names to WooCommerce product IDs. Example: {"Basic":123,"Pro":456}')} 
        </p>
        <form onSubmit={saveProfileMapping} className='mwp-form-grid' style={{maxWidth:600}}>
          <textarea className='mwp-input' rows={6} value={profileMap} onChange={function(e){ setProfileMap(e.target.value) }} />
          <div style={{display:'flex', gap:12}}>
            <button className='mwp-btn mwp-btn-primary' disabled={savingProfileMap}>{__('Save mapping')}</button>
            <button type='button' className='mwp-btn mwp-btn-ghost' onClick={fetchSettings}>{__('Reload')}</button>
          </div>
        </form>
      </div>
    </div>
  )
}
