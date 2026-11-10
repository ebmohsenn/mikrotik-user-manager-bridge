import React from 'react'
import PropTypes from 'prop-types'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, opts)
  let data = null
  try { data = await res.json() } catch (error) { console.warn('Failed to parse JSON response', error) }
  if (!res.ok) {
    const msg = (data && (data.message || data.error)) ? (data.message || data.error) : (`HTTP ${res.status}`)
    const err = new Error(msg); err.status = res.status; err.data = data; throw err
  }
  return data || {}
}

const initialForm = {
  user_id: '',
  username: '',
  email: '',
  name: '',
}

export default function Affiliates({ addToast }) {
  const base = (window.MWP_BOOTSTRAP?.rest?.mwpBase) || '/wp-json/mwp/v1'
  const nonce = (window.MWP_BOOTSTRAP?.rest?.nonce) || ''
  const i18nStrings = React.useMemo(() => window.MWP_BOOTSTRAP?.i18n || {}, [])
  const t = React.useCallback((key, fallback) => i18nStrings[key] || fallback, [i18nStrings])
  const headers = React.useMemo(() => ({ 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }), [nonce])

  const [loading, setLoading] = React.useState(true)
  const [affiliates, setAffiliates] = React.useState([])
  const [form, setForm] = React.useState(initialForm)
  const [saving, setSaving] = React.useState(false)

  const loadAffiliates = React.useCallback(() => {
    setLoading(true)
    fetchJSON(base + '/affiliates', { headers })
      .then((res) => {
        setAffiliates(res?.data || [])
      })
      .catch((err) => {
        console.error('Failed to load affiliates', err)
        addToast('error', err.message)
      })
      .finally(() => setLoading(false))
  }, [addToast, base, headers])

  React.useEffect(() => {
    loadAffiliates()
  }, [loadAffiliates])
  useRefreshRegistration(loadAffiliates)

  const resetForm = React.useCallback(() => {
    setForm(initialForm)
  }, [])

  const handleSubmit = (event) => {
    event.preventDefault()
    setSaving(true)
    const payload = {
      user_id: form.user_id ? parseInt(form.user_id, 10) : undefined,
      username: form.username || undefined,
      email: form.email || undefined,
      name: form.name || undefined,
    }
    fetchJSON(base + '/affiliates', { method: 'POST', headers, body: JSON.stringify(payload) })
      .then((res) => {
        addToast('success', 'Affiliate saved')
        if (res?.data?.generated_password) {
          const pwd = res.data.generated_password
          window.alert(`Affiliate account created. Temporary password: ${pwd}`)
        }
        resetForm()
        loadAffiliates()
        try { window.dispatchEvent(new CustomEvent('mwp:affiliates-updated')) } catch (error) { console.warn('Failed to dispatch affiliates update event', error) }
      })
      .catch((err) => addToast('error', err.message))
      .finally(() => setSaving(false))
  }

  const handleEdit = (affiliate) => {
    setForm({
      user_id: affiliate.id,
      username: affiliate.login,
      email: affiliate.email || '',
      name: affiliate.name || affiliate.login,
    })
  }

  const handleDelete = (id) => {
    if (!window.confirm('Remove this affiliate access?')) return
    fetchJSON(`${base}/affiliates/${id}`, { method: 'DELETE', headers })
      .then(() => {
        addToast('success', 'Affiliate removed')
        if (String(form.user_id) === String(id)) {
          resetForm()
        }
        loadAffiliates()
        try { window.dispatchEvent(new CustomEvent('mwp:affiliates-updated')) } catch (error) { console.warn('Failed to dispatch affiliates update event', error) }
      })
      .catch((err) => addToast('error', err.message))
  }

  return (
    <div>
          <form className='mwp-card mwp-form-card' onSubmit={handleSubmit}>
            <div className='mwp-heading'>{t('Affiliate profile', 'Affiliate profile')}</div>
            <div className='mwp-form-grid'>
              <input className='mwp-input' placeholder={t('Affiliate username', 'Affiliate username')} value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} />
              <input className='mwp-input' placeholder={t('Affiliate email', 'Email address')} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
              <input className='mwp-input' placeholder={t('Display name', 'Display name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              {form.user_id ? (
                <input type='hidden' value={form.user_id} />
              ) : null}
              <div style={{ display: 'flex', gap: 8 }}>
                <button className='mwp-btn mwp-btn-primary' disabled={saving}>{form.user_id ? t('Update affiliate', 'Update affiliate') : t('Add affiliate', 'Add affiliate')}</button>
                <button className='mwp-btn mwp-btn-ghost' type='button' onClick={resetForm} disabled={saving}>{t('Clear', 'Clear')}</button>
              </div>
              {form.user_id ? (
                <span className='mwp-muted'>{t('Editing affiliate', 'Editing affiliate')} ID {form.user_id}</span>
              ) : (
                <span className='mwp-muted'>{t('Affiliate instructions', 'Provide username + email to create a new affiliate.')}</span>
              )}
            </div>
          </form>

      <div className='mwp-card' style={{ marginTop: 24 }}>
        <div className='mwp-heading'>{t('Affiliates heading', 'Affiliates')}</div>
        {loading ? (
          <div className='mwp-loading'>Loading…</div>
        ) : affiliates.length === 0 ? (
          <div className='mwp-table-empty'>{t('No affiliates yet.', 'No affiliates yet.')}</div>
        ) : (
          <div className='mwp-table-wrapper'>
            <table className='mwp-table'>
              <thead>
                <tr>
                  <th>{t('Name', 'Name')}</th>
                  <th>{t('Users badge label', 'Users')}</th>
                  <th>{t('Actions', 'Actions')}</th>
                </tr>
              </thead>
              <tbody>
                {affiliates.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <div className='mwp-cell'>
                        <span className='mwp-cell-main'>{item.name}</span>
                        <span className='mwp-cell-sub'>{item.email || item.login}</span>
                      </div>
                    </td>
                    <td>
                      <span className='mwp-badge'>{item.users || 0}</span>
                    </td>
                    <td className='mwp-actions-cell'>
                      <div className='mwp-actions'>
                        <button className='mwp-btn mwp-btn-outline' type='button' onClick={() => handleEdit(item)}>{t('Edit', 'Edit')}</button>
                        <button className='mwp-btn mwp-btn-danger' type='button' onClick={() => handleDelete(item.id)}>{t('Delete', 'Remove')}</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}

Affiliates.propTypes = {
  addToast: PropTypes.func.isRequired,
}
