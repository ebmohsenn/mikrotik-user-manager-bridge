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

export default function Routers({ addToast }){
  const base = (window.MWP_BOOTSTRAP?.rest?.mwpBase) || '/wp-json/mwp/v1'
  const nonce = (window.MWP_BOOTSTRAP?.rest?.nonce)||''
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce, 'Content-Type':'application/json' }), [nonce])
  const [rows,setRows] = React.useState([])
  const [loading,setLoading] = React.useState(false)
  const [showNew,setShowNew] = React.useState(false)
  const [form,setForm] = React.useState({ name:'', address:'', 'shared-secret':'', 'coa-port':1700, disabled:false, comment:'' })
  const [editing,setEditing] = React.useState(null)
  const load = React.useCallback(()=>{
    setLoading(true)
    fetchJSON(base + '/routers', { headers }).then(d=>setRows(d?.data||[])).catch(e=>addToast('error', e.message)).finally(()=>setLoading(false))
  }, [addToast, base, headers])
  React.useEffect(()=>{ load() },[load])
  useRefreshRegistration(load)
  const submitNew = (e)=>{ e.preventDefault(); fetchJSON(base + '/routers', { method:'POST', headers, body: JSON.stringify(form) }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Create failed'); return; } addToast('success','Router added'); setShowNew(false); setForm({ name:'', address:'', 'shared-secret':'', 'coa-port':1700, disabled:false, comment:'' }); load(); }).catch(e=>addToast('error', e.message)) }
  const startEdit = (r)=>{ setEditing({ id: r.id||r.name, ...r }) }
  const saveEdit = (e)=>{ e.preventDefault(); const id = editing.id; const payload = { name: editing.name, address: editing.address, 'shared-secret': editing['shared-secret'], 'coa-port': parseInt(editing['coa-port']||1700,10), disabled: !!editing.disabled, comment: editing.comment }; fetchJSON(base + '/routers/' + encodeURIComponent(id), { method:'PATCH', headers, body: JSON.stringify(payload) }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Update failed'); return; } addToast('success','Router updated'); setEditing(null); load(); }).catch(e=>addToast('error', e.message)) }
  const remove = (id)=>{ if(!confirm('Remove this router?')) return; fetchJSON(base + '/routers/' + encodeURIComponent(id), { method:'DELETE', headers }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Delete failed'); return; } addToast('success','Router removed'); load(); }).catch(e=>addToast('error', e.message)) }
  const toggle = (r)=>{ const id = r.id||r.name; const payload = { disabled: !r.disabled }; fetchJSON(base + '/routers/' + encodeURIComponent(id), { method:'PATCH', headers, body: JSON.stringify(payload) }).then(res=>{ if(!res?.success){ addToast('error', res?.message||'Toggle failed'); return; } addToast('success', payload.disabled?'Disabled':'Enabled'); load(); }).catch(e=>addToast('error', e.message)) }
  const avatarLabel = (value)=>{
    const str = (value || '').toString().trim()
    if (!str) return 'RT'
    return str.slice(0, 2).toUpperCase()
  }
  return (
    <div>
      <div className='mwp-toolbar'>
        <button className='mwp-btn mwp-btn-primary' onClick={()=>setShowNew(true)}>Add Router</button>
      </div>
      {showNew && (
        <form onSubmit={submitNew} className='mwp-card mwp-form-card'>
          <div className='mwp-heading'>Add Router</div>
          <div className='mwp-form-grid'>
            <input className='mwp-input' placeholder='Name' value={form.name} onChange={e=>setForm({...form,name:e.target.value})} required />
            <input className='mwp-input' placeholder='Address' value={form.address} onChange={e=>setForm({...form,address:e.target.value})} required />
            <input className='mwp-input' placeholder='Shared secret' value={form['shared-secret']} onChange={e=>setForm({...form,'shared-secret':e.target.value})} required />
            <input className='mwp-input' type='number' placeholder='CoA port' value={form['coa-port']} onChange={e=>setForm({...form,'coa-port':e.target.value})} />
            <input className='mwp-input' placeholder='Comment' value={form.comment} onChange={e=>setForm({...form,comment:e.target.value})} />
            <label className='mwp-checkbox'><input type='checkbox' checked={!!form.disabled} onChange={e=>setForm({...form,disabled:e.target.checked})} /> Disabled</label>
            <div style={{display:'flex', gap:8}}>
              <button className='mwp-btn mwp-btn-primary'>Save</button>
              <button type='button' className='mwp-btn mwp-btn-ghost' onClick={()=>setShowNew(false)}>Cancel</button>
            </div>
          </div>
        </form>
      )}
      {loading ? (
        <div className='mwp-loading'>Loading…</div>
      ) : rows.length === 0 ? (
        <div className='mwp-table-empty'>No routers.</div>
      ) : (
        <div className='mwp-table-wrapper'>
          <table className='mwp-table'>
            <thead><tr><th>Name</th><th>Address</th><th>CoA Port</th><th>Status</th><th>Comment</th><th>Actions</th></tr></thead>
            <tbody>
            {rows.map((r,i)=> (
              editing && editing.id===(r.id||r.name) ? (
                <tr key={i}>
                  <td><input className='mwp-input' value={editing.name} onChange={e=>setEditing({...editing,name:e.target.value})} /></td>
                  <td><input className='mwp-input' value={editing.address||''} onChange={e=>setEditing({...editing,address:e.target.value})} /></td>
                  <td><input className='mwp-input' type='number' value={editing['coa-port']||1700} onChange={e=>setEditing({...editing,'coa-port':e.target.value})} /></td>
                  <td><label className='mwp-checkbox'><input type='checkbox' checked={!!editing.disabled} onChange={e=>setEditing({...editing,disabled:e.target.checked})} /> Disabled</label></td>
                  <td><input className='mwp-input' value={editing.comment||''} onChange={e=>setEditing({...editing,comment:e.target.value})} /></td>
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
                        <span className='mwp-cell-sub'>Router identity</span>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div className='mwp-entity'>
                      <span className='mwp-avatar mwp-avatar-muted'>{avatarLabel(r.address)}</span>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{r.address||''}</span>
                        <span className='mwp-cell-sub'>Host address</span>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div className='mwp-cell'>
                      <span className='mwp-cell-main'>{r['coa-port']||'—'}</span>
                      <span className='mwp-cell-sub'>CoA port</span>
                    </div>
                  </td>
                  <td>
                    <span className={`mwp-badge ${r.disabled ? 'mwp-badge-error' : 'mwp-badge-success'}`}>{r.disabled? 'Disabled':'Enabled'}</span>
                  </td>
                  <td>
                    <div className='mwp-cell'>
                      <span className='mwp-cell-main'>{r.comment||'—'}</span>
                    </div>
                  </td>
                  <td className='mwp-actions-cell'>
                    <div className='mwp-actions'>
                      <button className='mwp-btn mwp-btn-outline' onClick={()=>startEdit(r)}>Edit</button>
                      <button className='mwp-btn' onClick={()=>toggle(r)}>{r.disabled? 'Enable':'Disable'}</button>
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

Routers.propTypes = {
  addToast: PropTypes.func.isRequired
}
