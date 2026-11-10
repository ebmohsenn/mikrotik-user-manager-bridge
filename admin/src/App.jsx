import React from 'react'
import PropTypes from 'prop-types'
import './admin.css'
import UsersTable from './components/UsersTable.jsx'
import Profiles from './components/Profiles.jsx'
import UserProfiles from './components/UserProfiles.jsx'
import Groups from './components/Groups.jsx'
import Routers from './components/Routers.jsx'
import Dashboard from './components/Dashboard.jsx'
import Logs from './components/Logs.jsx'
import Settings from './components/Settings.jsx'
import Affiliates from './components/Affiliates.jsx'
import QueuePanel from './components/QueuePanel.jsx'
import RouterHealth from './components/RouterHealth.jsx'
import { ToastProvider, ToastContext } from './toast.jsx'
import { RefreshProvider, useRefreshControl } from './common/RefreshContext.jsx'

function TabButton(props){
  const cls = props.active ? 'mwp-btn mwp-btn-primary' : 'mwp-btn'
  return (
    <button className={cls} onClick={props.onClick}>
      {props.children}
    </button>
  )
}

TabButton.propTypes = {
  active: PropTypes.bool,
  onClick: PropTypes.func.isRequired,
  children: PropTypes.node
}

function DeployBadge(){
  const cfg = window.MWP_BOOTSTRAP?.ui?.badge
  const build = window.MWP_BOOTSTRAP?.build || {}
  if ( !cfg || cfg.show === false ) { return null }
  const readable = build.time || ''
  const hash = build.hash || ''
  const version = window.MWP_BOOTSTRAP?.version || ''
  let text = cfg.text || 'Deployed %time% · %hash%'
  text = text.replace('%time%', readable || '—')
             .replace('%hash%', hash || '—')
             .replace('%version%', version || '—')

  const meta = build.meta || {}
  let href = cfg.href || ''
  if ( !href ) {
    if ( cfg.link === 'commit' && meta.commit_url ) { href = meta.commit_url }
    else if ( cfg.link === 'run' && meta.run_url ) { href = meta.run_url }
    else if ( typeof cfg.link === 'string' && cfg.link.startsWith('http') ) { href = cfg.link }
  }

  const style = cfg.style || {}
  const badgeStyle = {
    background: style.bg || '#eef2ff',
    color: style.fg || '#1e3a8a',
    borderColor: style.border || '#c7d2fe'
  }

  const badge = (
    <span className='mwp-deploy-badge' style={badgeStyle} title={hash ? `Commit ${hash}` : undefined}>
      <span className='mwp-deploy-dot' />
      <span>{text}</span>
    </span>
  )

  if ( href ) {
    return <a className='mwp-deploy-link' href={href} target='_blank' rel='noreferrer'>{badge}</a>
  }
  return badge
}

function AppShell(){
  const [tab, setTab] = React.useState('Dashboard')
  const addToast = React.useContext(ToastContext)
  const { trigger, hasHandler } = useRefreshControl()
  const t = React.useCallback((key, fallback) => {
    const map = window.MWP_BOOTSTRAP?.i18n || {}
    return map[key] || fallback
  }, [])
  const branding = window.MWP_BOOTSTRAP?.branding || {}
  const brandName = branding.name || t('MikroTik Manager', 'MikroTik Manager')
  const brandLogo = branding.logo_url || ''
  return (
    <div>
      <div className='mwp-branding'>
        {brandLogo ? <img src={brandLogo} alt={brandName} className='mwp-branding-logo' /> : null}
        <div className='mwp-branding-text'>
          <div className='mwp-branding-name'>{brandName}</div>
          <div className='mwp-branding-tagline'>{t('MikroTik Manager', 'MikroTik Manager')}</div>
        </div>
      </div>
      <div className='mwp-header'>
        <div className='mwp-tab-container'>
          <div className='mwp-tab-group mwp-tab-group-primary'>
            <TabButton active={tab === 'Dashboard'} onClick={function(){ setTab('Dashboard') }}>{t('Dashboard', 'Dashboard')}</TabButton>
            <TabButton active={tab === 'Users'} onClick={function(){ setTab('Users') }}>{t('Users', 'Users')}</TabButton>
            <TabButton active={tab === 'Profiles'} onClick={function(){ setTab('Profiles') }}>{t('Subscriptions', 'Subscriptions')}</TabButton>
            <TabButton active={tab === 'Groups'} onClick={function(){ setTab('Groups') }}>{t('Locations', 'Locations')}</TabButton>
            <TabButton active={tab === 'UserProfiles'} onClick={function(){ setTab('UserProfiles') }}>{t('Subscription Status', 'Subscription Status')}</TabButton>
          </div>
          <div className='mwp-tab-group mwp-tab-group-secondary'>
            <TabButton active={tab === 'Routers'} onClick={function(){ setTab('Routers') }}>{t('Routers', 'Routers')}</TabButton>
            <TabButton active={tab === 'Affiliates'} onClick={function(){ setTab('Affiliates') }}>{t('Affiliates', 'Affiliates')}</TabButton>
            <TabButton active={tab === 'Queue'} onClick={function(){ setTab('Queue') }}>{t('Queue', 'Queue')}</TabButton>
            <TabButton active={tab === 'RouterHealth'} onClick={function(){ setTab('RouterHealth') }}>{t('Router Health', 'Router Health')}</TabButton>
            <TabButton active={tab === 'Logs'} onClick={function(){ setTab('Logs') }}>{t('Logs', 'Logs')}</TabButton>
            <TabButton active={tab === 'Settings'} onClick={function(){ setTab('Settings') }}>{t('Settings', 'Settings')}</TabButton>
          </div>
        </div>
        <div className='mwp-header-actions'>
          <button type='button' className='mwp-btn mwp-btn-outline' onClick={trigger} disabled={!hasHandler}>
            <span className='mwp-icon mwp-icon-refresh'></span>
            {t('Refresh', 'Refresh')}
          </button>
          <DeployBadge />
        </div>
      </div>
      {tab === 'Dashboard' ? <Dashboard /> : null}
      {tab === 'Users' ? <UsersTable addToast={addToast || function(){}} /> : null}
      {tab === 'Profiles' ? <Profiles addToast={addToast || function(){}} /> : null}
      {tab === 'UserProfiles' ? <UserProfiles addToast={addToast || function(){}} /> : null}
      {tab === 'Groups' ? <Groups addToast={addToast || function(){}} /> : null}
      {tab === 'Routers' ? <Routers addToast={addToast || function(){}} /> : null}
      {tab === 'Affiliates' ? <Affiliates addToast={addToast || function(){}} /> : null}
      {tab === 'Logs' ? <Logs /> : null}
      {tab === 'Settings' ? <Settings /> : null}
      {tab === 'Queue' ? <QueuePanel /> : null}
      {tab === 'RouterHealth' ? <RouterHealth /> : null}
    </div>
  )
}

export default function App(){
  return (
    <ToastProvider>
      <RefreshProvider>
        <AppShell />
      </RefreshProvider>
    </ToastProvider>
  )
}
