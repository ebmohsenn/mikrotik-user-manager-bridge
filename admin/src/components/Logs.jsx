import React from 'react'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

function useRest(){
  const bootstrap = (typeof window !== 'undefined' && window.MWP_BOOTSTRAP) ? window.MWP_BOOTSTRAP : {}
  const base = (bootstrap.rest && (bootstrap.rest.mwpBase || bootstrap.rest.mikrotikBase)) || '/wp-json/mwp/v1'
  const nonce = (bootstrap.rest && bootstrap.rest.nonce) || ''
  return { base, nonce }
}

export default function Logs(){
  const { base, nonce } = useRest()
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce }), [nonce])
  const [rows, setRows] = React.useState([])
  const [q, setQ] = React.useState({ level:'', action:'', s:'' })
  const load = React.useCallback(function(){
    const params = new URLSearchParams()
    if (q.level) params.set('level', q.level)
    if (q.action) params.set('action', q.action)
    if (q.s) params.set('s', q.s)
    fetch(base + '/logs?' + params.toString(), { headers })
      .then(function(r){ return r.json() })
      .then(function(d){ setRows((d && d.data) || []) })
      .catch(function(error){ console.error('Failed to load logs', error) })
  }, [base, headers, q.level, q.action, q.s])
  React.useEffect(function(){ load() }, [load])
  useRefreshRegistration(load)
  const csvUrl = (typeof window !== 'undefined' && window.ajaxurl ? window.ajaxurl : '/wp-admin/admin-ajax.php').replace('admin-ajax.php','admin-post.php') + '?action=mumb_logs_csv'
  const avatarLabel = function(value){
    const str = (value || '').toString().trim()
    if (!str) return 'LG'
    return str.slice(-2).toUpperCase()
  }
  return (
    <div>
      <h2>Logs</h2>
      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>Filters</div>
        <div className='mwp-form-grid'>
          <select className='mwp-select' value={q.level} onChange={function(e){ setQ(Object.assign({}, q, { level: e.target.value })) }}>
            <option value=''>All levels</option>
            <option value='info'>Info</option>
            <option value='warning'>Warning</option>
            <option value='error'>Error</option>
          </select>
          <input className='mwp-input' placeholder='Action' value={q.action} onChange={function(e){ setQ(Object.assign({}, q, { action: e.target.value })) }} />
          <input className='mwp-input' placeholder='Search message' value={q.s} onChange={function(e){ setQ(Object.assign({}, q, { s: e.target.value })) }} />
          <div style={{display:'flex', gap:8}}>
            <button className='mwp-btn mwp-btn-primary' onClick={load}>Apply</button>
            <a className='mwp-btn mwp-btn-outline' href={csvUrl}>Export CSV</a>
          </div>
        </div>
      </div>
      {rows.length === 0 ? <div className='mwp-table-empty'>No logs.</div> : (
        <div className='mwp-table-wrapper'>
          <table className='mwp-table'>
            <thead><tr><th>ID</th><th>Time</th><th>Level</th><th>Action</th><th>Message</th></tr></thead>
            <tbody>
              {rows.map(function(r, i){
                const lvl = (r.level||'').toString().toLowerCase();
                let badge = 'mwp-badge-muted';
                if (lvl === 'error') badge = 'mwp-badge-error';
                else if (lvl === 'warning') badge = 'mwp-badge-warning';
                else if (lvl === 'info') badge = 'mwp-badge-info';
                return (
                  <tr key={i}>
                    <td>
                      <div className='mwp-entity'>
                        <span className='mwp-avatar'>{avatarLabel(r.id)}</span>
                        <div className='mwp-cell'>
                          <span className='mwp-cell-main'>{r.id}</span>
                          <span className='mwp-cell-sub'>Entry</span>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{r.time}</span>
                        <span className='mwp-cell-sub'>Timestamp</span>
                      </div>
                    </td>
                    <td><span className={`mwp-badge ${badge}`}>{r.level}</span></td>
                    <td>
                      <div className='mwp-entity'>
                        <span className='mwp-avatar mwp-avatar-muted'>{avatarLabel(r.action)}</span>
                        <div className='mwp-cell'>
                          <span className='mwp-cell-main'>{r.action}</span>
                          <span className='mwp-cell-sub'>Action</span>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{r.message}</span>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
