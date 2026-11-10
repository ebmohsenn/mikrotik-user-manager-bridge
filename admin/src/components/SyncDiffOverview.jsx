import React from 'react'
import PropTypes from 'prop-types'
import { useRest } from '../hooks/useRest.js'
import { useRefreshRegistration } from '../common/RefreshContext.jsx'

function useI18n() {
  return React.useMemo(() => window.MWP_BOOTSTRAP?.i18n || {}, [])
}

function tFactory(map) {
  return function translate(key, fallback) {
    if (!key) {
      return fallback
    }
    return map[key] || fallback
  }
}

function SummaryList({ title, items, emptyMessage }) {
  if (!items || items.length === 0) {
    return (
      <div className='mwp-section'>
        <div className='mwp-section-head'><em>{title}</em></div>
        <p className='mwp-muted'>{emptyMessage}</p>
      </div>
    )
  }
  return (
    <div className='mwp-section'>
      <div className='mwp-section-head'><em>{title}</em></div>
      <ul className='mwp-list'>
        {items.map((item, index) => (
          <li key={index}>{typeof item === 'string' ? item : JSON.stringify(item)}</li>
        ))}
      </ul>
    </div>
  )
}

SummaryList.propTypes = {
  title: PropTypes.string.isRequired,
  items: PropTypes.arrayOf(PropTypes.oneOfType([PropTypes.string, PropTypes.object])),
  emptyMessage: PropTypes.string.isRequired,
}

export default function SyncDiffOverview({ title, footer, registerRefresh = true, onRefreshRef }) {
  const i18nMap = useI18n()
  const t = React.useCallback(tFactory(i18nMap), [i18nMap])
  const { base, nonce } = useRest()
  const [diff, setDiff] = React.useState(null)
  const [loading, setLoading] = React.useState(false)
  const [error, setError] = React.useState('')

  const load = React.useCallback(() => {
    setLoading(true)
    setError('')
    fetch(base + '/sync/diff', { headers: { 'X-WP-Nonce': nonce } })
      .then(async (response) => {
        const text = await response.text()
        try {
          const payload = JSON.parse(text)
          if (payload && payload.success === false) {
            throw new Error(payload.message || 'API returned error')
          }
          const data = payload && payload.data ? payload.data : {}
          setDiff(data)
        } catch (parseErr) {
          // If JSON parsing failed, the response is likely HTML (error page)
          console.error('Failed to parse JSON response:', text.substring(0, 200))
          throw new Error(`Invalid JSON response. Got: ${text.substring(0, 100)}...`)
        }
      })
      .catch((err) => {
        const message = err && err.message ? err.message : t('Sync diff error', 'Failed to load differences')
        setError(message)
      })
      .finally(() => {
        setLoading(false)
      })
  }, [base, nonce, t])

  React.useEffect(() => { load() }, [load])
  React.useEffect(() => {
    if (typeof onRefreshRef === 'function') {
      onRefreshRef(load)
      return () => { onRefreshRef(null) }
    }
    return undefined
  }, [load, onRefreshRef])
  useRefreshRegistration(registerRefresh ? load : undefined)

  const summaryTitle = title || t('Sync Diff Overview', 'Sync Diff Overview')
  const emptyMessage = t('No differences detected between WordPress and MikroTik.', 'No differences detected between WordPress and MikroTik.')
  const routerExtrasGroups = t('Router extras: groups', 'Router extras: groups')
  const routerExtrasProfiles = t('Router extras: profiles', 'Router extras: profiles')
  const routerExtrasUsers = t('Router extras: users', 'Router extras: users')
  const routerExtrasUserProfiles = t('Router extras: user profiles', 'Router extras: user profiles')
  const footerMessage = footer || t('Review differences here, then use the Sync tools in Settings to apply changes.', 'Review differences here, then use the Sync tools in Settings to apply changes.')

  return (
    <div className='mwp-card mwp-form-card'>
      <div className='mwp-heading'>{summaryTitle}</div>
      {loading ? <p className='mwp-loading'>{t('Loading diff…', 'Loading diff…')}</p> : null}
      {error ? <p className='mwp-error'>{error}</p> : null}
      {!loading && !error && (!diff || Object.keys(diff).length === 0) ? (
        <p className='mwp-muted'>{emptyMessage}</p>
      ) : null}
      {!loading && !error && diff ? (
        <div>
          <div className='mwp-grid-2' style={{ marginBottom: 16 }}>
            <div className='mwp-cell'>
              <span className='mwp-cell-sub'>{t('Creates pending', 'Creates pending')}</span>
              <span className='mwp-cell-main'>
                {t('Groups', 'Groups')} {(diff.create && diff.create.groups && diff.create.groups.length) || 0}
              </span>
              <span className='mwp-cell-sub'>
                {t('Profiles', 'Profiles')} {(diff.create && diff.create.profiles && diff.create.profiles.length) || 0}
              </span>
            </div>
            <div className='mwp-cell'>
              <span className='mwp-cell-sub'>{t('Users & assignments', 'Users & assignments')}</span>
              <span className='mwp-cell-main'>
                {t('Users', 'Users')} {(diff.create && diff.create.users && diff.create.users.length) || 0}
              </span>
              <span className='mwp-cell-sub'>
                {t('User profiles', 'User profiles')} {(diff.create && diff.create.user_profiles && diff.create.user_profiles.length) || 0}
              </span>
            </div>
            <div className='mwp-cell'>
              <span className='mwp-cell-sub'>{t('Updates', 'Updates')}</span>
              <span className='mwp-cell-main'>
                {t('Users', 'Users')} {(diff.update && diff.update.users && diff.update.users.length) || 0}
              </span>
            </div>
          </div>
          <SummaryList
            title={routerExtrasGroups}
            items={(diff.delete && diff.delete.groups) || []}
            emptyMessage={t('No extra groups detected.', 'No extra groups detected.')}
          />
          <SummaryList
            title={routerExtrasProfiles}
            items={(diff.delete && diff.delete.profiles) || []}
            emptyMessage={t('No extra profiles detected.', 'No extra profiles detected.')}
          />
          <SummaryList
            title={routerExtrasUsers}
            items={(diff.delete && diff.delete.users) || []}
            emptyMessage={t('No extra users detected.', 'No extra users detected.')}
          />
          <SummaryList
            title={routerExtrasUserProfiles}
            items={(diff.delete && diff.delete.user_profiles)
              ? diff.delete.user_profiles.map((row) => `${row.username}:${row.profile}`)
              : []}
            emptyMessage={t('No extra user-profile assignments detected.', 'No extra user-profile assignments detected.')}
          />
          <p className='mwp-muted' style={{ marginTop: 12 }}>{footerMessage}</p>
        </div>
      ) : null}
    </div>
  )
}

SyncDiffOverview.propTypes = {
  title: PropTypes.string,
  footer: PropTypes.string,
  registerRefresh: PropTypes.bool,
  onRefreshRef: PropTypes.func,
}
