import React from 'react'
import PropTypes from 'prop-types'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, opts)
  let data = null
  try {
    data = await res.json()
  } catch (error) {
    console.warn('Failed to parse JSON response', error)
  }
  if (!res.ok) {
    const msg = (data && (data.message || data.error)) ? (data.message || data.error) : `HTTP ${res.status}`
    const err = new Error(msg)
    err.status = res.status
    err.data = data
    throw err
  }
  return data || {}
}

function unique(values) {
  return Array.from(new Set(values.filter(Boolean)))
}

function deriveLocationsFromUsers(users) {
  return unique((users || []).map((r) => (r && (r.group || r['actual-group'] || '')).toString()))
}

function computeEffectiveLocations(users, apiGroups) {
  const namesApi = (apiGroups || []).map((x) => x?.name || x).filter(Boolean)
  const namesUser = deriveLocationsFromUsers(users || [])
  return unique([...namesApi, ...namesUser]).map((n) => ({ name: n }))
}

export default function Groups({ addToast }){
  const base = (window.MWP_BOOTSTRAP?.rest?.mwpBase) || '/wp-json/mwp/v1'
  const nonce = (window.MWP_BOOTSTRAP?.rest?.nonce)||''
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce }), [nonce])
  const [rows,setRows] = React.useState([]) // rows: [{name}]
  const [loading,setLoading] = React.useState(false)

  const load = React.useCallback(()=>{
    setLoading(true)
    Promise.all([
      fetchJSON(base + '/users',  { headers }).catch((error)=>{
        console.error('Failed to load users for location list', error)
        return { data: [] }
      }),
      fetchJSON(base + '/groups', { headers }).catch((error)=>{
        console.error('Failed to load locations from API', error)
        return { data: [] }
      }),
    ])
      .then(([u,g])=>{
        const users = u?.data||[]
        const groups = g?.data||[]
        setRows(computeEffectiveLocations(users, groups))
      })
      .catch(e=>addToast('error', e.message))
      .finally(()=>setLoading(false))
  }, [addToast, base, headers])

  const refresh = React.useCallback(()=>{
    setLoading(true)
    Promise.all([
      fetchJSON(base + '/users',  { headers }).catch((error)=>{
        console.error('Failed to refresh users for location recompute', error)
        return { data: [] }
      }),
      fetchJSON(base + '/groups', { headers }).catch((error)=>{
        console.error('Failed to refresh locations from API', error)
        return { data: [] }
      }),
    ])
      .then(([u,g])=>{
        const users = u?.data||[]
        const groups = g?.data||[]
        let eff = computeEffectiveLocations(users, groups)
        if (eff.length > 0) { setRows(eff); addToast('success','Locations refreshed'); return }
        // If still empty, sync mirror then recompute
        return fetchJSON(base + '/groups/sync', { method:'POST', headers: { 'X-WP-Nonce': nonce } })
          .then(()=> fetchJSON(base + '/groups', { headers }))
          .then(g2=>{ eff = computeEffectiveLocations(users, g2?.data||[]); setRows(eff); addToast('success','Locations synced') })
      })
      .catch(e=>addToast('error', e.message))
      .finally(()=>setLoading(false))
  }, [addToast, base, headers, nonce])

  React.useEffect(()=>{ load() },[load])
  useRefreshRegistration(refresh)
  const avatarLabel = (value)=>{
    const str = (value || '').toString().trim()
    if (!str) return '#'
    return str.slice(0, 2).toUpperCase()
  }

  return (
    <div>
      <div className='mwp-toolbar'></div>
      {loading ? <div className='mwp-loading'>Loading…</div> : (
        rows.length === 0 ? <div className='mwp-table-empty'>No locations.</div> : (
          <div className='mwp-table-wrapper'>
            <table className='mwp-table'>
              <thead><tr><th>Location (VPN Server)</th></tr></thead>
              <tbody>
                {rows.map((r,i)=> (
                  <tr key={i}>
                    <td>
                      <div className='mwp-entity'>
                        <span className='mwp-avatar'>{avatarLabel(r.name)}</span>
                        <div className='mwp-cell'>
                          <span className='mwp-cell-main'>{r.name||''}</span>
                          <span className='mwp-cell-sub'>RADIUS Server Location</span>
                        </div>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}
      <p className='description'>This list shows VPN server locations (RADIUS configurations) synced from MikroTik. Editing locations is disabled.</p>
    </div>
  )
}

Groups.propTypes = {
  addToast: PropTypes.func.isRequired
}
