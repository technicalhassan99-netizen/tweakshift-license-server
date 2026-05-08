import express from 'express'
import cors from 'cors'
import crypto from 'crypto'
import Freemius from '@freemius/sdk'

const app = express()
const PORT = process.env.PORT || 10000
const APP_SHARED_SECRET = process.env.APP_SHARED_SECRET || ''
const FREEMIUS_PRODUCT_ID = process.env.FREEMIUS_PRODUCT_ID || ''
const FREEMIUS_PUBLIC_KEY = process.env.FREEMIUS_PUBLIC_KEY || ''
const FREEMIUS_SECRET_KEY = process.env.FREEMIUS_SECRET_KEY || ''

const fs = new Freemius({
  productId: FREEMIUS_PRODUCT_ID,
  publicKey: FREEMIUS_PUBLIC_KEY,
  secretKey: FREEMIUS_SECRET_KEY,
})

app.use(cors({ origin: '*', methods: ['GET', 'POST'] }))
app.use(express.json({ limit: '64kb' }))

function clean(value) {
  return String(value || '').trim()
}

function mask(key) {
  if (!key) return ''
  return key.slice(0, 4) + '...' + key.slice(-4)
}

app.get('/health', (_req, res) => {
  res.json({ ok: true, service: 'tweakshift-license-server' })
})

app.post('/api/license/verify', async (req, res) => {
  try {
    const licenseKey = clean(req.body.licenseKey || req.body.license_key)
    const email = clean(req.body.email)
    if (!licenseKey) return res.status(400).json({ valid: false, error: 'License key is required.' })

    const payload = await fs.api.post(
      `/products/${FREEMIUS_PRODUCT_ID}/licenses/activations.json`,
      { license_key: licenseKey, plugin_id: FREEMIUS_PRODUCT_ID }
    )

    console.log('Freemius response:', JSON.stringify(payload))

    const license = payload.license || payload
    const isActive = license.is_active === true || license.activated > 0 || payload.install_id > 0
    const isExpired = license.expiration && license.expiration !== 'lifetime' && new Date(license.expiration).getTime() < Date.now()
    const valid = Boolean(isActive && !isExpired)

    if (!valid) return res.status(403).json({ valid: false, error: 'License is inactive, expired, or not valid.' })

    return res.json({
      valid: true,
      active: true,
      licenseKey: mask(licenseKey),
      plan: license.plan_name || 'Premium',
      customerEmail: license.customer_email || email || '',
      expiresAt: license.expiration || null,
      source: 'freemius-sdk',
    })
  } catch (err) {
    console.error('License error:', err)
    return res.status(500).json({ valid: false, error: err.message })
  }
})

app.post('/api/webhooks/freemius', (req, res) => {
  if (APP_SHARED_SECRET && req.query.secret !== APP_SHARED_SECRET) return res.status(401).json({ ok: false })
  const signature = crypto.createHash('sha256').update(JSON.stringify(req.body)).digest('hex')
  console.log('Freemius webhook received:', { signature, type: req.body?.type || req.body?.event })
  res.json({ ok: true })
})

app.listen(PORT, () => console.log(`TweakShift license server running on port ${PORT}`))