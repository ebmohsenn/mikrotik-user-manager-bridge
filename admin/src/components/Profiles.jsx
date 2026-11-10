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

export default function Profiles({ addToast }){
  const base = (window.MWP_BOOTSTRAP?.rest?.mwpBase) || '/wp-json/mwp/v1'
  const nonce = (window.MWP_BOOTSTRAP?.rest?.nonce)||''
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce, 'Content-Type':'application/json' }), [nonce])
  const syncHeaders = React.useMemo(() => ({ 'X-WP-Nonce': nonce }), [nonce])
  const [rows,setRows] = React.useState([])
  const [loading,setLoading] = React.useState(false)
  const [showNew,setShowNew] = React.useState(false)
  const [form,setForm] = React.useState({ name:'', 'rate-limit':'', validity:'', price:'' })
  const [editing,setEditing] = React.useState(null)
  const unitLabels = React.useMemo(() => ({
    d: ['day', 'days'],
    w: ['week', 'weeks'],
    m: ['month', 'months'],
    y: ['year', 'years']
  }), [])
  const formatValidityValue = React.useCallback((raw) => {
    if (raw === null || raw === undefined) {
      return '—'
    }
    const str = raw.toString().trim()
    if (!str) {
      return '—'
    }
    const tokens = str.match(/(\d+)\s*([a-zA-Z]+)/g)
    if (tokens && tokens.length) {
      const parts = tokens.map((token) => {
        const match = token.trim().match(/^(\d+)\s*([a-zA-Z]+)/)
        if (!match) {
          return null
        }
        const amount = parseInt(match[1], 10)
        if (Number.isNaN(amount)) {
          return null
        }
        const unitKey = match[2].toLowerCase()[0]
        const labels = unitLabels[unitKey]
        if (!labels) {
          return null
        }
        const label = amount === 1 ? labels[0] : labels[1]
        return `${amount} ${label}`
      }).filter(Boolean)
      if (parts.length) {
        return parts.join(', ')
      }
    }
    return str
  }, [unitLabels])
  const load = React.useCallback(()=>{
    setLoading(true)
    fetchJSON(base + '/profiles', { headers }).then(d=>setRows(d?.data||[])).catch(e=>addToast('error', e.message)).finally(()=>setLoading(false))
  }, [addToast, base, headers])
  const refresh = React.useCallback(()=>{
    fetchJSON(base + '/profiles', { headers }).then(d=>{
      const list = d?.data||[]
      if (list.length > 0) { setRows(list); addToast('success','Subscriptions refreshed'); return; }
      return fetchJSON(base + '/profiles/sync', { method:'POST', headers: syncHeaders })
        .then(()=>{ addToast('success','Subscriptions synced'); return fetchJSON(base + '/profiles', { headers }) })
        .then(d2=>setRows(d2?.data||[]))
    }).catch(e=>addToast('error', e.message))
  }, [addToast, base, headers, syncHeaders])
  React.useEffect(()=>{ load() },[load])
  useRefreshRegistration(refresh)
  const submitNew = (e)=>{ e.preventDefault(); fetchJSON(base + '/profiles', { method:'POST', headers, body: JSON.stringify(form) }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Create failed'); return; } addToast('success','Subscription created'); setShowNew(false); setForm({ name:'', 'rate-limit':'', validity:'', price:'' }); load(); }).catch(e=>addToast('error', e.message)) }
  const startEdit = (r)=>{ setEditing({ id: r.id||r.name, ...r }) }
  const saveEdit = (e)=>{ e.preventDefault(); const id = editing.id; const payload = { name: editing.name, 'rate-limit': editing['rate-limit'], validity: editing.validity, price: editing.price }; fetchJSON(base + '/profiles/' + encodeURIComponent(id), { method:'PATCH', headers, body: JSON.stringify(payload) }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Update failed'); return; } addToast('success','Subscription updated'); setEditing(null); load(); }).catch(e=>addToast('error', e.message)) }
  const remove = (id)=>{ if(!confirm('Delete this subscription plan?')) return; fetchJSON(base + '/profiles/' + encodeURIComponent(id), { method:'DELETE', headers }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Delete failed'); return; } addToast('success','Subscription deleted'); load(); }).catch(e=>addToast('error', e.message)) }
  const avatarLabel = (value)=>{
    const str = (value || '').toString().trim()
    if (!str) return 'PF'
    return str.slice(0, 2).toUpperCase()
  }
  return (
    <div>
      <div className='mwp-toolbar'>
        <button className='mwp-btn mwp-btn-primary' onClick={()=>setShowNew(true)}>New Subscription Plan</button>
      </div>
      {showNew && (
        <form onSubmit={submitNew} className='mwp-card mwp-form-card'>
          <div className='mwp-heading'>Create Subscription Plan</div>
          <div className='mwp-form-grid'>
            <input className='mwp-input' placeholder='Plan Name' value={form.name} onChange={e=>setForm({...form,name:e.target.value})} required />
            <input className='mwp-input' placeholder='Rate-limit (bandwidth)' value={form['rate-limit']} onChange={e=>setForm({...form,'rate-limit':e.target.value})} />
            <input className='mwp-input' placeholder='Validity (e.g. 1m, 3m)' value={form.validity} onChange={e=>setForm({...form,validity:e.target.value})} />
            <input className='mwp-input' placeholder='Price' value={form.price} onChange={e=>setForm({...form,price:e.target.value})} />
            <div style={{display:'flex', gap:8}}>
              <button className='mwp-btn mwp-btn-primary'>Create</button>
              <button type='button' className='mwp-btn mwp-btn-ghost' onClick={()=>setShowNew(false)}>Cancel</button>
            </div>
          </div>
        </form>
      )}
      {loading ? (
        <div className='mwp-loading'>Loading…</div>
      ) : rows.length === 0 ? (
        <div className='mwp-table-empty'>No subscription plans.</div>
      ) : (
        <div className='mwp-table-wrapper'>
          <table className='mwp-table'>
            <thead><tr><th>Name</th><th>Rate-limit</th><th>Validity</th><th>Price</th><th>Actions</th></tr></thead>
            <tbody>
            {rows.map((r,i)=> (
              editing && editing.id===(r.id||r.name) ? (
                <tr key={i}>
                  <td><input className='mwp-input' value={editing.name} onChange={e=>setEditing({...editing,name:e.target.value})} /></td>
                  <td><input className='mwp-input' value={editing['rate-limit']||''} onChange={e=>setEditing({...editing,'rate-limit':e.target.value})} /></td>
                  <td><input className='mwp-input' value={editing.validity||''} onChange={e=>setEditing({...editing,validity:e.target.value})} /></td>
                  <td><input className='mwp-input' value={editing.price||''} onChange={e=>setEditing({...editing,price:e.target.value})} /></td>
                  <td className='mwp-actions-cell'>
                    <div className='mwp-actions'>
                      <button className='mwp-btn mwp-btn-primary' onClick={saveEdit}>Save</button>
                      <button className='mwp-btn mwp-btn-ghost' onClick={()=>setEditing(null)}>Cancel</button>
                    </div>
                  </td>
                </tr>
              ) : (
                <tr key={i}>
                  <td>
                    <div className='mwp-entity'>
                      <span className='mwp-avatar'>{avatarLabel(r.name)}</span>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{r.name||''}</span>
                        <span className='mwp-cell-sub'>Subscription plan</span>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div className='mwp-cell'>
                      <span className='mwp-cell-main'>{r['rate-limit']||'—'}</span>
                      <span className='mwp-cell-sub'>Rate limit</span>
                    </div>
                  </td>
                  <td>
                    <div className='mwp-cell'>
                      <span className='mwp-cell-main'>{formatValidityValue(r.validity)}</span>
                      <span className='mwp-cell-sub'>Raw: {r.validity || '—'}</span>
                    </div>
                  </td>
                  <td>
                    <div className='mwp-cell'>
                      <span className='mwp-cell-main'>{r.price||'—'}</span>
                    </div>
                  </td>
                  <td className='mwp-actions-cell'>
                    <div className='mwp-actions'>
                      <button className='mwp-btn mwp-btn-outline' onClick={()=>startEdit(r)}>Edit</button>
                      <button className='mwp-btn mwp-btn-danger' onClick={()=>remove(r.id||r.name)}>Delete</button>
                    </div>
                  </td>
                </tr>
              )
            ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

Profiles.propTypes = {
  addToast: PropTypes.func.isRequired
}
