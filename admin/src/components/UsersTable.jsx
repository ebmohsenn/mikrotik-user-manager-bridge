import React from 'react'
import PropTypes from 'prop-types'
import { useUsers, invalidateUsersCache } from './useUsers'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

async function fetchJSON(url, opts={}){
  const res = await fetch(url, opts)
  let data = null
  try { data = await res.json() } catch(error) { console.warn('Failed to parse JSON response', error) }
  if (!res.ok) {
    const msg = (data && (data.message || data.error)) ? (data.message || data.error) : (`HTTP ${res.status}`)
    const err = new Error(msg); err.status = res.status; err.data = data; throw err
  }
  return data || {}
}

export default function UsersTable({ addToast }){
  const base = (window.MWP_BOOTSTRAP?.rest?.mwpBase) || '/wp-json/mwp/v1'
  const nonce = (window.MWP_BOOTSTRAP?.rest?.nonce)||''
  const i18nStrings = React.useMemo(() => window.MWP_BOOTSTRAP?.i18n || {}, [])
  const t = React.useCallback((key, fallback) => i18nStrings[key] || fallback, [i18nStrings])
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce, 'Content-Type':'application/json' }), [nonce])
  const [showNew,setShowNew] = React.useState(false)
  const [form,setForm] = React.useState({ username:'', password:'', group:'', comment:'', shared_users:1, disabled:false })
  const [editing,setEditing] = React.useState(null)
  const [groups,setGroups] = React.useState([])
  const [profiles,setProfiles] = React.useState([])
  const [affiliates,setAffiliates] = React.useState([])
  const toBool = (v)=> (v===true || v===1 || v==='1' || v==='true' || v==='yes' || v==='on')
  const getAccountStatus = (row)=>{
    if (!row) return 'active'
    if (toBool(row.disabled)) return 'expired'
    const rawState = [row.status, row['status-text'], row.state, row['actual-state'], row['account-status']]
      .map((val)=> (val===undefined || val===null) ? '' : String(val).toLowerCase())
      .find((txt)=> txt !== '') || ''
    if (rawState) {
      if (/(wait|pend|install|queue)/.test(rawState)) return 'waiting'
      if (/(expir|disable|error|terminate|stop)/.test(rawState)) return 'expired'
    }
    const expiry = row.expires_at || row['expires-at'] || row.expire_at || row['expire-at'] || row['end_time'] || row['end-time']
    if (expiry) {
      const ts = Date.parse(expiry)
      if (!Number.isNaN(ts) && ts < Date.now()) return 'expired'
    }
    return 'active'
  }
  const avatarLabel = (value)=>{
    const str = (value || '').toString().trim()
    if (!str) return '??'
    return str.slice(0, 2).toUpperCase()
  }

  const [page, setPage] = React.useState(1)
  const [perPage, setPerPage] = React.useState(25)
  const [search, setSearch] = React.useState('')
  const [force, setForce] = React.useState(false)

  const { loading, response, error } = useUsers(base, headers, { page, perPage, search, force })
  const rows = React.useMemo(() => response?.data || [], [response])
  const meta = React.useMemo(() => (
    response?.meta || { page: 1, per_page: perPage, total: rows.length, total_pages: 1 }
  ), [perPage, response, rows.length])

  React.useEffect(() => {
    if (error) { addToast('error', error.message) }
  }, [addToast, error])

  React.useEffect(() => {
    // When fetching users, also refresh groups/profiles maps for selectors
    fetchJSON(base + '/groups', { headers }).then((g)=>{
      const apiGroups = (g?.data||[]).map(x=> x?.name || x).filter(Boolean)
      setGroups(apiGroups)
    }).catch((err)=>{ console.error('Failed to load groups', err) })
    fetchJSON(base + '/profiles', { headers }).then((p)=>{
      const apiProfiles = (p?.data||[]).map(x=> x?.name || x).filter(Boolean)
      setProfiles(apiProfiles)
    }).catch((err)=>{ console.error('Failed to load profiles', err) })
    const loadAffiliates = () => {
      fetchJSON(base + '/affiliates', { headers }).then((res)=>{
        const list = Array.isArray(res?.data) ? res.data : []
        setAffiliates(list)
      }).catch((err)=>{ console.error('Failed to load affiliates', err) })
    }
    loadAffiliates()
    const handler = () => { loadAffiliates() }
    try { window.addEventListener('mwp:affiliates-updated', handler) } catch (error) { console.warn('Failed to register affiliates listener', error) }
    return () => {
      try { window.removeEventListener('mwp:affiliates-updated', handler) } catch (error) { console.warn('Failed to unregister affiliates listener', error) }
    }
  }, [base, headers])

  const [uPMap,setUPMap] = React.useState({})
  const [useLiveUP,setUseLiveUP] = React.useState(()=>{ 
    try{ 
      const v=window.localStorage.getItem('mwp_use_live_user_profiles'); 
      return v? v==='1' : true 
    }catch(e){ 
      return true 
    } 
  })
  const [usingLiveUPEff,setUsingLiveUPEff] = React.useState(useLiveUP)
  const [fellBackUP,setFellBackUP] = React.useState(false)
  
  React.useEffect(()=>{
    try { 
      window.localStorage.setItem('mwp_use_live_user_profiles', useLiveUP? '1':'0') 
    } catch(e) { 
      console.warn('Failed to save live user profiles preference', e) 
    }
  }, [useLiveUP])
  
  React.useEffect(()=>{ 
    setUsingLiveUPEff(!fellBackUP && useLiveUP) 
  },[fellBackUP,useLiveUP])

  React.useEffect(() => {
    if (!loading && force) {
      setForce(false)
    }
  }, [loading, force])

  // Live user profiles fetching
  React.useEffect(() => {
    if (!useLiveUP) return
    let cancelled = false
    
    fetchJSON(`${base}/users/profiles/live`, { headers })
      .then((res) => {
        if (cancelled) return
        const map = {}
        const list = Array.isArray(res?.data) ? res.data : []
        for (const item of list) {
          const uname = (item.username || '').toLowerCase().trim()
          const pr = item.profile || ''
          if (!uname || !pr) continue
          if (!map[uname]) map[uname] = []
          if (!map[uname].includes(pr)) map[uname].push(pr)
        }
        setUPMap(map)
        setUsingLiveUPEff(useLiveUP)
      })
      .catch((err) => {
        if (cancelled) return
        if (useLiveUP) {
          setFellBackUP(true)
          console.error('Failed to fetch live user profiles map', err)
        }
      })
    
    return () => { cancelled = true }
  }, [base, headers, useLiveUP, loading])

  useRefreshRegistration(React.useCallback(() => {
    setForce(true)
  }, [setForce]))

  React.useEffect(() => {
    const derivedGroups = rows.map(r => (r && (r.group || r['actual-group'] || '')).toString()).filter(Boolean)
    setGroups(prev => {
      const combined = Array.from(new Set([...prev, ...derivedGroups]))
      return combined.length === prev.length && combined.every((val, idx) => prev[idx] === val) ? prev : combined
    })
    const derivedProfiles = rows.flatMap(r => Array.isArray(r.profiles) ? r.profiles : [(r && (r['actual-profile']||''))]).filter(Boolean)
    setProfiles(prev => {
      const combined = Array.from(new Set([...prev, ...derivedProfiles]))
      return combined.length === prev.length && combined.every((val, idx) => prev[idx] === val) ? prev : combined
    })
  }, [rows])

  React.useEffect(() => {
    let cancelled = false
    fetchJSON(`${base}/user-profiles`, { headers })
      .then((res) => {
        if (cancelled) return
        const data = res?.data || []
        const map = {}
        for (const row of data) {
          const uname = (row.username || '').toString().toLowerCase().trim()
          const pr = (row.profile || '').toString()
          if (!uname || !pr) continue
          if (!map[uname]) map[uname] = []
          if (!map[uname].includes(pr)) map[uname].push(pr)
        }
        setUPMap(map)
      })
      .catch((err) => {
        console.error('Failed to fetch user profiles map', err)
        if (!cancelled) {
          setUPMap({})
        }
      })
    return () => { cancelled = true }
  }, [base, headers, force, page, perPage, search])

  React.useEffect(()=>{
    const h = ()=> { invalidateUsersCache(); setForce(true); }
    try { window.addEventListener('mwp:user-profiles-updated', h) } catch(error) { console.warn('Failed to register user profile listener', error) }
    return ()=>{ try { window.removeEventListener('mwp:user-profiles-updated', h) } catch(error) { console.warn('Failed to unregister user profile listener', error) } }
  }, [])

  const submitNew = (e)=>{ e.preventDefault();
    const payload = { 
      username: form.username, 
      password: form.password, 
      group: form.group, 
      comment: form.comment, 
      'shared-users': parseInt(form.shared_users||1,10), 
      disabled: !!form.disabled 
    }
    if (form.profile) payload.profile = form.profile
    fetchJSON(base + '/users', { method:'POST', headers, body: JSON.stringify(payload) })
      .then(res=>{ if(!res?.success){ addToast('error', res?.message||t('User create failed', 'Create failed')); return; } addToast('success', t('User created', 'User created')); setShowNew(false); setForm({ username:'', password:'', group:'', comment:'', shared_users:1, disabled:false }); invalidateUsersCache(); setPage(1); setForce(true); try{ window.dispatchEvent(new CustomEvent('mwp:user-profiles-updated')); }catch(error){ console.warn('Failed to dispatch user profile update event', error) } })
      .catch(e=>addToast('error', e.message))
  }
  const remove = (id)=>{ if(!confirm(t('Delete user confirmation', 'Delete this user?'))) return; fetchJSON(base + '/users/' + encodeURIComponent(id), { method:'DELETE', headers }).then(res=>{ if(!res?.success){ addToast('error', res?.message||t('User delete failed', 'Delete failed')); return; } addToast('success', t('User deleted', 'User deleted')); invalidateUsersCache(); setForce(true); try{ window.dispatchEvent(new CustomEvent('mwp:user-profiles-updated')) }catch(error){ console.warn('Failed to dispatch user profile update event', error) } }).catch(e=>addToast('error', e.message)) }
  const startEdit = (r)=>{
    const unameKey = (r.username || r.name || '').toLowerCase().trim();
    const currentProfiles = uPMap[unameKey] || (Array.isArray(r.profiles) ? r.profiles : []);
    const currentProfile = Array.isArray(currentProfiles) && currentProfiles.length > 0 ? currentProfiles[0] : (r['actual-profile'] || '');
    
    setEditing({
      id: r.id || r.name || r.username,
      username: r.username||r.name,
      group: r.group||'',
      profile: currentProfile,
      comment: r.comment||'',
      shared_users: r['shared-users']||1,
      disabled: toBool(r.disabled),
      _orig: {
        group: r.group||'',
        profile: currentProfile,
        comment: r.comment||'',
        shared_users: r['shared-users']||1,
        disabled: toBool(r.disabled),
        affiliate_owner: r?.affiliate_owner?.id ? String(r.affiliate_owner.id) : ''
      },
      affiliate_owner: r?.affiliate_owner?.id ? String(r.affiliate_owner.id) : ''
    })
  }
  const saveEdit = (e)=>{
    e.preventDefault();
    const id = editing.id;
    const payload = {};
    // Only send fields that actually changed
    if (editing.group !== editing._orig.group && editing.group !== '') payload.group = editing.group;
    if (editing.profile !== editing._orig.profile && editing.profile !== '') payload.profile = editing.profile;
    if (editing.comment !== editing._orig.comment && editing.comment !== '') payload.comment = editing.comment;
    const suNow = parseInt(editing.shared_users||1,10); const suOrig = parseInt(editing._orig.shared_users||1,10);
    if (suNow !== suOrig) payload['shared-users'] = suNow;
    if (!!editing.disabled !== !!editing._orig.disabled) payload.disabled = !!editing.disabled;
    if (editing.affiliate_owner !== editing._orig.affiliate_owner) {
      payload.affiliate_owner = editing.affiliate_owner || '';
    }
    fetchJSON(base + '/users/' + encodeURIComponent(id), { method:'PATCH', headers, body: JSON.stringify(payload) })
      .then(res=>{ if(!res?.success){ addToast('error', res?.message||t('User update failed', 'Update failed')); return; } addToast('success', t('User updated', 'User updated')); setEditing(null); invalidateUsersCache(); setForce(true); try{ window.dispatchEvent(new CustomEvent('mwp:user-profiles-updated')); }catch(error){ console.warn('Failed to dispatch user profile update event', error) } })
      .catch(e=>addToast('error', e.message))
  }

  const handleForceRefresh = React.useCallback(() => {
    setForce(true)
    setFellBackUP(false)
  }, [])

  return (
    <div>
      <div className='mwp-toolbar'>
        <button className='mwp-btn mwp-btn-primary' onClick={()=>setShowNew(true)}>{t('New User', 'New User')}</button>
        <button className='mwp-btn' onClick={handleForceRefresh}>{t('Force Refresh', 'Force Refresh')}</button>
        <label className='mwp-checkbox'><input type='checkbox' checked={useLiveUP} onChange={e=>setUseLiveUP(!!e.target.checked)} /> {t('Live profiles', 'Live profiles')}</label>
        <span className='mwp-muted'>
          {usingLiveUPEff ? (fellBackUP ? 'Live unavailable — showing mirror' : 'Showing live') : 'Showing mirror'}
        </span>
        <input className='mwp-input' placeholder={t('Search users…', 'Search users…')} value={search} onChange={(e)=>{ setSearch(e.target.value); setPage(1); invalidateUsersCache(); }} />
      </div>
      {showNew && (
        <form onSubmit={submitNew} className='mwp-card mwp-form-card'>
          <div className='mwp-heading'>Create User</div>
          <div className='mwp-form-grid'>
            <input className='mwp-input' placeholder='Username' value={form.username} onChange={e=>setForm({...form,username:e.target.value})} required />
            <input className='mwp-input' placeholder='Password' value={form.password} onChange={e=>setForm({...form,password:e.target.value})} required />
            <select className='mwp-select' value={form.group} onChange={e=>setForm({...form,group:e.target.value})}>
              <option value=''>Select location (optional)</option>
              {groups.map(g=> <option key={g} value={g}>{g}</option>)}
            </select>
            <select className='mwp-select' value={form.profile||''} onChange={e=>setForm({...form,profile:e.target.value})}>
              <option value=''>Select subscription (optional)</option>
              {profiles.map(p=> <option key={p} value={p}>{p}</option>)}
            </select>
            <input className='mwp-input' placeholder='Shared users' type='number' min='1' value={form.shared_users} onChange={e=>setForm({...form,shared_users:e.target.value})} />
            <input className='mwp-input' placeholder='Comment' value={form.comment} onChange={e=>setForm({...form,comment:e.target.value})} />
            <label className='mwp-checkbox'><input type='checkbox' checked={!!form.disabled} onChange={e=>setForm({...form,disabled:e.target.checked})} /> Disabled</label>
            <div style={{display:'flex', gap:8}}>
              <button className='mwp-btn mwp-btn-primary'>{t('Create User', 'Create')}</button>
              <button type='button' className='mwp-btn mwp-btn-ghost' onClick={()=>setShowNew(false)}>{t('Cancel', 'Cancel')}</button>
            </div>
          </div>
        </form>
      )}
      {(() => {
        if (loading) {
          return <div className='mwp-loading'>Loading…</div>
        }
        if (rows.length === 0) {
          return <div className='mwp-table-empty'>{t('No users', 'No users.')}</div>
        }
        return (
          <div className='mwp-table-wrapper'>
            <table className='mwp-table'>
              <thead><tr><th>{t('Username', 'Username')}</th><th>{t('Location', 'Location')}</th><th>{t('Subscription', 'Subscription')}</th><th>{t('Shared', 'Shared')}</th><th>{t('Comment', 'Comment')}</th><th>{t('Affiliate column', 'Affiliate')}</th><th>{t('Status', 'Status')}</th><th>{t('Actions', 'Actions')}</th></tr></thead>
              <tbody>
            {rows.map((r,i)=> {
              const accountStatus = getAccountStatus(r)
              const affiliateOwner = r?.affiliate_owner || null
              const ownerName = affiliateOwner?.name || affiliateOwner?.login || ''
              const ownerContact = affiliateOwner?.email || affiliateOwner?.login || ''
              const ownerGroups = Array.isArray(r?.affiliate_owner_groups) && r.affiliate_owner_groups.length > 0 ? r.affiliate_owner_groups.join(', ') : ''
              const ownerSub = ownerName ? (ownerGroups ? `${ownerContact ? ownerContact + ' · ' : ''}${ownerGroups}` : (ownerContact || t('Not assigned', 'Not assigned'))) : t('Not assigned', 'Not assigned')
              return editing && (editing.username===(r.username||r.name)) ? (
                <tr key={i}>
                  <td>
                    <div className='mwp-entity'>
                      <span className={`mwp-status-line mwp-status-line-${accountStatus}`}></span>
                      <span className='mwp-avatar'>{avatarLabel(editing.username)}</span>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{editing.username}</span>
                        {editing.id ? <span className='mwp-cell-sub'>UM ID: {editing.id}</span> : null}
                      </div>
                    </div>
                  </td>
                  <td>
                    <select className='mwp-select' value={editing.group} onChange={e=>setEditing({...editing,group:e.target.value})}>
                      <option value=''>No change</option>
                      {groups.map(g=> <option key={g} value={g}>{g}</option>)}
                    </select>
                  </td>
                  <td>
                    <select className='mwp-select' value={editing.profile||''} onChange={e=>setEditing({...editing,profile:e.target.value})}>
                      <option value=''>No change</option>
                      {profiles.map(p=> <option key={p} value={p}>{p}</option>)}
                    </select>
                  </td>
                  <td><input className='mwp-input' type='number' min='1' value={editing.shared_users} onChange={e=>setEditing({...editing,shared_users:e.target.value})} /></td>
                  <td><input className='mwp-input' value={editing.comment} onChange={e=>setEditing({...editing,comment:e.target.value})} /></td>
                  <td>
                    <select className='mwp-select' value={editing.affiliate_owner} onChange={e=>setEditing({...editing,affiliate_owner:e.target.value})}>
                      <option value=''>No affiliate</option>
                      {affiliates.map((aff)=> (
                        <option key={aff.id} value={String(aff.id)}>{aff.name || aff.login}</option>
                      ))}
                    </select>
                  </td>
                  <td><label className='mwp-checkbox'><input type='checkbox' checked={!!editing.disabled} onChange={e=>setEditing({...editing,disabled:e.target.checked})} /> Disabled</label></td>
                  <td className='mwp-actions-cell'>
                    <div className='mwp-actions'>
                      <button className='mwp-btn mwp-btn-primary' onClick={saveEdit}>Save</button>
                      <button className='mwp-btn mwp-btn-ghost' onClick={()=>setEditing(null)}>Cancel</button>
                    </div>
                  </td>
                </tr>
              ) : (()=>{
                const username = (r.username || r.name || '').toString();
                const identifier = (r.id || r.um_id || '').toString();
                const login = (r.login || '').toString();
                const unameKey = username.toLowerCase().trim();
                const profileList = (()=>{
                  const fallbackKey = login.toLowerCase().trim();
                  const list = uPMap[unameKey] || (fallbackKey ? uPMap[fallbackKey] : undefined);
                  if (Array.isArray(list) && list.length > 0) { return list.join(', '); }
                  if (Array.isArray(r.profiles)) { return r.profiles.join(', '); }
                  return r['actual-profile'] || '';
                })();
                const sharedUsers = r['shared-users'] || '';
                const disabled = toBool(r.disabled);
                const profileSource = usingLiveUPEff ? 'Live profile data' : 'Mirror profile data';
                const metaLine = identifier ? `UM ID: ${identifier}` : (login && login !== username ? `Login: ${login}` : '');
                return (
                  <tr key={i}>
                  <td>
                    <div className='mwp-entity'>
                      <span className={`mwp-status-line mwp-status-line-${accountStatus}`}></span>
                      <span className='mwp-avatar'>{avatarLabel(username)}</span>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{username || '—'}</span>
                        {metaLine ? <span className='mwp-cell-sub'>{metaLine}</span> : null}
                      </div>
                      </div>
                    </td>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{r.group || '—'}</span>
                        <span className='mwp-cell-sub'>VPN Location</span>
                      </div>
                    </td>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{profileList || '—'}</span>
                        <span className='mwp-cell-sub'>{profileSource.replace('profile', 'subscription')}</span>
                      </div>
                    </td>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{sharedUsers || '—'}</span>
                        <span className='mwp-cell-sub'>Shared users</span>
                      </div>
                    </td>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{r.comment || '—'}</span>
                      </div>
                    </td>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{ownerName || '—'}</span>
                        <span className='mwp-cell-sub'>{ownerSub || 'Not assigned'}</span>
                      </div>
                    </td>
                    <td>
                      <span className={`mwp-badge ${disabled ? 'mwp-badge-error' : 'mwp-badge-success'}`}>{disabled ? 'Disabled' : 'Active'}</span>
                    </td>
                    <td className='mwp-actions-cell'>
                      <div className='mwp-actions'>
                        <button className='mwp-btn mwp-btn-outline' onClick={()=>startEdit(r)}>{t('Edit', 'Edit')}</button>
                        <button className='mwp-btn' onClick={()=>{ const pw = window.prompt(`Enter new password for ${(r.username||r.name||'')}`); if(pw===null) return; if(!pw){ addToast('error','Password cannot be empty'); return;} fetchJSON(base + '/users/' + encodeURIComponent(r.id||r.name||r.username), { method:'PATCH', headers, body: JSON.stringify({ password: pw }) }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Password reset failed'); return; } addToast('success', t('Reset Password', 'Password updated')) }).catch(e=>addToast('error', e.message)) }}>{t('Reset Password', 'Reset Password')}</button>
                        <button className='mwp-btn mwp-btn-danger' onClick={()=>remove(r.id||r.name||r.username)}>{t('Delete', 'Delete')}</button>
                      </div>
                    </td>
                  </tr>
                )
              })()
            })}
              </tbody>
            </table>
            <div className='mwp-table-footer'>
              <button className='mwp-btn' disabled={page <= 1} onClick={()=> setPage(Math.max(1, page - 1))}>Previous</button>
              <span className='mwp-muted'>Page {meta.page} of {meta.total_pages} · Total {meta.total}</span>
              <button className='mwp-btn' disabled={meta.total_pages <= meta.page} onClick={()=> setPage(Math.min(meta.total_pages, page + 1))}>Next</button>
              <select className='mwp-select' value={perPage} onChange={e=>{ setPage(1); setPerPage(parseInt(e.target.value,10)); }}>
                {[10,25,50,100].map(n=> <option key={n} value={n}>{n}/page</option>)}
              </select>
            </div>
          </div>
        )
      })()}
    </div>
  )
}

UsersTable.propTypes = {
  addToast: PropTypes.func.isRequired
}
