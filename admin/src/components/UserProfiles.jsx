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

export default function UserProfiles({ addToast }){
  const base = (window.MWP_BOOTSTRAP?.rest?.mwpBase) || '/wp-json/mwp/v1'
  const nonce = (window.MWP_BOOTSTRAP?.rest?.nonce)||''
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }), [nonce])
  const authHeaders = React.useMemo(() => ({ 'X-WP-Nonce': nonce }), [nonce])
  const [rows,setRows] = React.useState([])
  const [loading,setLoading] = React.useState(false)
  const [users,setUsers] = React.useState([])
  const [profiles,setProfiles] = React.useState([])
  const [form,setForm] = React.useState({ username:'', profile:'' })
  const load = React.useCallback(async () => {
    setLoading(true)
    try {
      const [up, u, p] = await Promise.all([
        fetchJSON(`${base}/user-profiles`, { headers }).catch((error)=>{
          console.error('Failed to fetch user profiles', error)
          return { data: [], __err: true }
        }),
        fetchJSON(base + '/users', { headers: authHeaders }).catch((error)=>{
          console.error('Failed to fetch users for user-profiles view', error)
          return { data: [] }
        }),
        fetchJSON(base + '/profiles', { headers: authHeaders }).catch((error)=>{
          console.error('Failed to fetch profiles for user-profiles view', error)
          return { data: [] }
        })
      ])

      const listRaw = Array.isArray(up?.data) ? up.data : []
      const uniq = {}
      const dedup = []
      for (const row of listRaw) {
        const username = row.username || row.user || row.users || row.name || ''
        const profile = row.profile || ''
        if (!username || !profile) continue
        const k = `${username}|${profile}`
        if (uniq[k]) continue
        uniq[k] = 1
        dedup.push({
          username,
          profile,
          state: row.state || '',
          end_time: row.end_time || row['end-time'] || row.expires_at || ''
        })
      }
      setRows(dedup)

      const usersRaw = (u?.data || []).map(r => r.username || r.name).filter(Boolean)
      setUsers(Array.from(new Set(usersRaw)))
      const profilesRaw = (p?.data || []).map(r => r.name || r).filter(Boolean)
      setProfiles(Array.from(new Set(profilesRaw)))
    } catch (error) {
      addToast('error', error && error.message ? error.message : 'Failed to load user profiles')
    } finally {
      setLoading(false)
    }
  }, [addToast, authHeaders, base, headers])

  const refresh = React.useCallback(()=>{
    fetchJSON(base + '/user-profiles', { headers }).then(d=>{
      const list = d?.data||[]
      if (list.length > 0) { setRows(list); addToast('success','User profiles refreshed'); return }
      return fetchJSON(base + '/user-profiles/sync', { method:'POST', headers: authHeaders })
        .then(()=> fetchJSON(base + '/user-profiles', { headers }))
        .then(d2=>{ setRows(d2?.data||[]); addToast('success','User profiles synced') })
    }).catch(e=>addToast('error', e.message))
  }, [addToast, authHeaders, base, headers])

  React.useEffect(()=>{ load() },[load])
  useRefreshRegistration(load)
  const avatarLabel = (value)=>{
    const str = (value || '').toString().trim()
    if (!str) return 'UP'
    return str.slice(0, 2).toUpperCase()
  }
  return (
    <div>
      <div className='mwp-toolbar'>
        <button className='button' onClick={()=>{
          fetch(base + '/user-profiles/sync', { method:'POST', headers: authHeaders })
            .catch((error)=>{ console.error('Failed to start user profile sync', error) })
            .finally(()=>{ refresh() })
        }}>Sync Mirror</button>
      </div>
      <div className='mwp-card mwp-form-card'>
        <div className='mwp-heading'>Assign Profile to User</div>
        <div className='mwp-form-grid'>
          <select className='mwp-select' value={form.username} onChange={e=>setForm({...form,username:e.target.value})}>
            <option value=''>Select user</option>
            {users.map(u=> <option key={u} value={u}>{u}</option>)}
          </select>
          <select className='mwp-select' value={form.profile} onChange={e=>setForm({...form,profile:e.target.value})}>
            <option value=''>Select profile</option>
            {profiles.map(p=> <option key={p} value={p}>{p}</option>)}
          </select>
          <div style={{display:'flex', gap:8}}>
            <button className='mwp-btn mwp-btn-primary' onClick={()=>{
              if(!form.username || !form.profile){ addToast('error','Select user and profile'); return; }
              fetchJSON(base + '/user-profiles', { method:'POST', headers, body: JSON.stringify(form) })
                .then(res=>{ if(!res?.success){ addToast('error', res?.message||'Assign failed'); return; } addToast('success','Profile assigned'); setForm({ username:'', profile:'' }); try{ window.dispatchEvent(new CustomEvent('mwp:user-profiles-updated')); }catch(error){ console.warn('Failed to dispatch user profile update event', error) } refresh(); })
                .catch(e=>addToast('error', e.message))
            }}>Assign</button>
            <button className='mwp-btn mwp-btn-ghost' type='button' onClick={()=>setForm({ username:'', profile:'' })}>Clear</button>
          </div>
        </div>
      </div>
      {loading ? <div className='mwp-loading'>Loading…</div> : (
        rows.length === 0 ? <div className='mwp-table-empty'>No user-profile assignments.</div> : (
          <div className='mwp-table-wrapper'>
            <table className='mwp-table'>
              <thead><tr><th>User</th><th>Profile</th><th>Status</th><th>Expires</th><th>Actions</th></tr></thead>
              <tbody>
                {rows.map((r,i)=> {
                  const state = (r.state||'').toString();
                  const badgeClass = state.toLowerCase()==='running' ? 'mwp-badge-success' : state.toLowerCase()==='error' ? 'mwp-badge-error' : state ? 'mwp-badge-info' : 'mwp-badge-muted';
                  const badgeLabel = state || 'Unknown';
                  return (
                    <tr key={i}>
                      <td>
                        <div className='mwp-entity'>
                          <span className='mwp-avatar'>{avatarLabel(r.username)}</span>
                          <div className='mwp-cell'>
                            <span className='mwp-cell-main'>{r.username||''}</span>
                            <span className='mwp-cell-sub'>User</span>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div className='mwp-entity'>
                          <span className='mwp-avatar mwp-avatar-muted'>{avatarLabel(r.profile)}</span>
                          <div className='mwp-cell'>
                            <span className='mwp-cell-main'>{r.profile||''}</span>
                            <span className='mwp-cell-sub'>Profile</span>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span className={`mwp-badge ${badgeClass}`}>{badgeLabel}</span>
                      </td>
                      <td>
                        <div className='mwp-cell'>
                          <span className='mwp-cell-main'>{r.end_time||r.expires_at||'—'}</span>
                          <span className='mwp-cell-sub'>Expiration</span>
                        </div>
                      </td>
                      <td className='mwp-actions-cell'>
                        <div className='mwp-actions'>
                          <button className='mwp-btn mwp-btn-danger' onClick={()=>{
                          if(!confirm('Remove this profile from user?')) return;
                          fetchJSON(base + '/user-profiles?username=' + encodeURIComponent(r.username) + '&profile=' + encodeURIComponent(r.profile), { method:'DELETE', headers: authHeaders })
                            .then(res=>{ if(!res?.success){ addToast('error', res?.message||'Remove failed'); return; } addToast('success','Removed'); try{ window.dispatchEvent(new CustomEvent('mwp:user-profiles-updated')); }catch(error){ console.warn('Failed to dispatch user profile update event', error) } refresh(); })
                            .catch(e=>addToast('error', e.message))
                          }}>Remove</button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )
      )}
      <p className='description'>These are assignments of users to profiles mirrored from MikroTik. Use Users tab to assign a profile.</p>
    </div>
  )
}

UserProfiles.propTypes = {
  addToast: PropTypes.func.isRequired
}
