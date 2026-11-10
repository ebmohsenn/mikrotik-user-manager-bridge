import React from 'react'
import PropTypes from 'prop-types'

export const ToastContext = React.createContext(function noop(){})

export function ToastProvider({ children }){
  const [toasts, setToasts] = React.useState([])
  const addToast = React.useCallback(function(type, message, ms){
    const id = Math.random().toString(36).slice(2)
    const ttl = typeof ms === 'number' ? ms : 3000
    setToasts(function(prev){ return prev.concat([{ id, type, message }]) })
    setTimeout(function(){ setToasts(function(prev){ return prev.filter(function(t){ return t.id !== id }) }) }, ttl)
  }, [])
  return (
    <ToastContext.Provider value={addToast}>
      {children}
      <div className='mwp-toast-container'>
        {toasts.map(function(t){
          const typeClass = t.type === 'error' ? 'mwp-toast-error' : 
                           t.type === 'warning' ? 'mwp-toast-warning' :
                           t.type === 'info' ? 'mwp-toast-info' : 'mwp-toast-success'
          return (
            <div key={t.id} className={`mwp-toast ${typeClass}`}>
              <div className='mwp-toast-icon'>
                {t.type === 'error' ? '✕' : 
                 t.type === 'warning' ? '⚠' :
                 t.type === 'info' ? 'ⓘ' : '✓'}
              </div>
              <div className='mwp-toast-message'>{t.message}</div>
            </div>
          )
        })}
      </div>
    </ToastContext.Provider>
  )
}

ToastProvider.propTypes = {
  children: PropTypes.node
}
