export function useRest(){
  const bootstrap = (typeof window !== 'undefined' && window.MWP_BOOTSTRAP) ? window.MWP_BOOTSTRAP : {}
  const base = (bootstrap.rest && (bootstrap.rest.mwpBase || bootstrap.rest.mikrotikBase)) || '/wp-json/mwp/v1'
  const nonce = (bootstrap.rest && bootstrap.rest.nonce) || ''
  return { base, nonce }
}
