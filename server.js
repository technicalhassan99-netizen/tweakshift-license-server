const express = require('express')
const cors = require('cors')
const axios = require('axios')
const fs = require('fs')
const path = require('path')
require('dotenv').config()

const app = express()
app.use(cors())
app.use(express.json())

// GUMROAD_PRODUCT_ID = "Vc5fvNvynz6O4N_PzVShSA==" (the actual product_id, NOT permalink)
const GUMROAD_PRODUCT_ID  = process.env.GUMROAD_PRODUCT_ID
const MAX_DEVICES          = parseInt(process.env.MAX_DEVICES_PER_LICENSE) || 2
const PORT                 = parseInt(process.env.PORT) || 3001
const ACTIVATIONS_FILE     = path.join(__dirname, 'activations.json')

let inMemoryActivations = {}

function loadActivations() {
  try {
    if (fs.existsSync(ACTIVATIONS_FILE)) {
      const data = JSON.parse(fs.readFileSync(ACTIVATIONS_FILE, 'utf8'))
      inMemoryActivations = { ...inMemoryActivations, ...data }
    }
  } catch {}
  return inMemoryActivations
}

function saveActivations(data) {
  inMemoryActivations = data
  try {
    fs.writeFileSync(ACTIVATIONS_FILE, JSON.stringify(data, null, 2), 'utf8')
  } catch {
    console.warn('Could not write activations.json — using in-memory only')
  }
}

loadActivations()

app.post('/api/verify-license', async (req, res) => {
  const { licenseKey, deviceId } = req.body

  if (!licenseKey || !deviceId) {
    return res.status(400).json({ success: false, message: 'License key and device ID are required.' })
  }

  if (!GUMROAD_PRODUCT_ID) {
    return res.status(500).json({ success: false, message: 'Server config error: GUMROAD_PRODUCT_ID not set.' })
  }

  const cleanKey = licenseKey.trim().toUpperCase()
  let gumroadValid = false
  let licenseData = null

  try {
    // Use product_id (not product_permalink) — required for this product
    const params = new URLSearchParams({
      product_id: GUMROAD_PRODUCT_ID,
      license_key: cleanKey,
      increment_uses_count: 'false'
    })

    console.log('Verifying with Gumroad | product_id:', GUMROAD_PRODUCT_ID, '| key:', cleanKey.slice(0, 8) + '...')

    const response = await axios.post(
      'https://api.gumroad.com/v2/licenses/verify',
      params.toString(),
      { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, timeout: 10000 }
    )

    console.log('Gumroad response:', response.status, JSON.stringify(response.data))
    licenseData = response.data
    if (response.data.success === true) gumroadValid = true

  } catch (err) {
    if (err.response) {
      console.log('Gumroad HTTP error:', err.response.status, JSON.stringify(err.response.data))
      if (err.response.status === 404) {
        return res.json({ success: false, message: 'Invalid license key. Please check and try again.' })
      }
      licenseData = err.response.data
    } else {
      console.error('Network error:', err.message)
      return res.status(502).json({ success: false, message: 'Could not reach Gumroad. Try again in a moment.' })
    }
  }

  if (!gumroadValid) {
    const msg = licenseData?.message || 'Invalid license key.'
    console.warn('License rejected:', cleanKey.slice(0, 8), '|', msg)
    return res.json({ success: false, message: msg })
  }

  // Device limit check
  const activations = loadActivations()
  const record = activations[cleanKey] || { devices: [] }

  if (record.devices.includes(deviceId)) {
    console.log('Re-activation OK:', cleanKey.slice(0, 8))
    return res.json({ success: true, plan: 'premium', premiumUnlocked: true })
  }

  if (record.devices.length >= MAX_DEVICES) {
    return res.json({
      success: false,
      message: `License already used on ${MAX_DEVICES} device(s). Contact support to reset.`
    })
  }

  record.devices.push(deviceId)
  record.activatedAt = record.activatedAt || new Date().toISOString()
  record.lastSeen = new Date().toISOString()
  activations[cleanKey] = record
  saveActivations(activations)

  console.log('New activation:', cleanKey.slice(0, 8), '| Total devices:', record.devices.length)
  return res.json({ success: true, plan: 'premium', premiumUnlocked: true })
})

app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    product_id: GUMROAD_PRODUCT_ID || 'NOT SET',
    activations: Object.keys(inMemoryActivations).length,
    timestamp: new Date().toISOString()
  })
})

app.get('/', (req, res) => {
  res.json({ name: 'TweakShift License Server', status: 'running' })
})

app.listen(PORT, () => {
  console.log(`TweakShift License Server on port ${PORT}`)
  console.log(`Product ID: ${GUMROAD_PRODUCT_ID || 'NOT SET'}`)
})
