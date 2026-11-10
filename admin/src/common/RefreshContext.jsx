import React from 'react'
import PropTypes from 'prop-types'

const RefreshContext = React.createContext({
  register: () => () => {},
  trigger: () => {},
  hasHandler: false,
})

export function RefreshProvider({ children }) {
  const handlerRef = React.useRef(null)
  const [hasHandler, setHasHandler] = React.useState(false)

  const register = React.useCallback((handler) => {
    handlerRef.current = typeof handler === 'function' ? handler : null
    setHasHandler(typeof handler === 'function')
    return () => {
      if (handlerRef.current === handler) {
        handlerRef.current = null
        setHasHandler(false)
      }
    }
  }, [])

  const trigger = React.useCallback(() => {
    if (typeof handlerRef.current === 'function') {
      handlerRef.current()
    }
  }, [])

  const value = React.useMemo(() => ({ register, trigger, hasHandler }), [register, trigger, hasHandler])

  return (
    <RefreshContext.Provider value={value}>
      {children}
    </RefreshContext.Provider>
  )
}

RefreshProvider.propTypes = {
  children: PropTypes.node,
}

export function useRefreshRegistration(handler) {
  const { register } = React.useContext(RefreshContext)
  React.useEffect(() => {
    if (typeof handler !== 'function') {
      return undefined
    }
    const unregister = register(handler)
    return unregister
  }, [handler, register])
}

export function useRefreshControl() {
  return React.useContext(RefreshContext)
}

export default RefreshContext
