const express = require('express')
const cors = require('cors')
const axios = require('axios')
const fs = require('fs')
const path = require('path')
require('dotenv').config()

const app = express()
app.use(cors())
app.use(express.json())

// ── Config ────────────────────────────────────────────────
const GUMROAD_ACCESS_TOKEN = process.env.GUMROAD_ACCESS_TOKEN
const GUMROAD_PRODUCT_ID   = process.env.GUMROAD_PRODUCT_ID
const MAX_DEVICES          = parseInt(process.env.MAX_DEVICES_PER_LICENSE) || 2
const PORT                 = parseInt(process.env.PORT) || 3001
const ACTIVATIONS_FILE     = path.join(__dirname, 'activations.json')

// ── Load/save activations ─────────────────────────────────
function loadActivations() {
  try {
    if (!fs.existsSync(ACTIVATIONS_FILE)) return {}
    return JSON.parse(fs.readFileSync(ACTIVATIONS_FILE, 'utf8'))
  } catch { return {} }
}

function saveActivations(data) {
  fs.writeFileSync(ACTIVATIONS_FILE, JSON.stringify(data, null, 2), 'utf8')
}

// ── POST /api/verify-license ──────────────────────────────
app.post('/api/verify-license', async (req, res) => {
  const { licenseKey, deviceId } = req.body

  if (!licenseKey || !deviceId) {
    return res.status(400).json({ success: false, message: 'License key and device ID are required.' })
  }

  // Verify with Gumroad
  let gumroadValid = false
  let licenseData = null
  try {
    const response = await axios.post('https://api.gumroad.com/v2/licenses/verify', {
      product_id: GUMROAD_PRODUCT_ID,
      license_key: licenseKey,
    }, {
      headers: { Authorization: `Bearer ${GUMROAD_ACCESS_TOKEN}` },
      timeout: 8000
    })
    if (response.data.success) {
      gumroadValid = true
      licenseData = response.data
    }
  } catch (err) {
    console.error('Gumroad verify error:', err.message)
    return res.status(502).json({ success: false, message: 'Could not reach Gumroad to verify license. Try again.' })
  }

  if (!gumroadValid) {
    return res.status(200).json({ success: false, message: 'Invalid license key. Purchase at tweakshift.gumroad.com' })
  }

  // Check device limit
  const activations = loadActivations()
  const licenseRecord = activations[licenseKey] || { devices: [] }

  // If same device already activated → success
  if (licenseRecord.devices.includes(deviceId)) {
    return res.json({ success: true, plan: 'premium', premiumUnlocked: true })
  }

  // Check if device limit reached
  if (licenseRecord.devices.length >= MAX_DEVICES) {
    return res.status(200).json({
      success: false,
      message: `Device limit reached (${MAX_DEVICES} devices per license). Contact support to reset.`
    })
  }

  // Register new device
  licenseRecord.devices.push(deviceId)
  licenseRecord.activatedAt = licenseRecord.activatedAt || new Date().toISOString()
  licenseRecord.lastSeen = new Date().toISOString()
  activations[licenseKey] = licenseRecord
  saveActivations(activations)

  console.log(`✓ License activated: ${licenseKey.slice(0, 8)}... | Device: ${deviceId.slice(0, 8)}...`)

  return res.json({ success: true, plan: 'premium', premiumUnlocked: true })
})

// ── Health check ──────────────────────────────────────────
app.get('/health', (req, res) => res.json({ status: 'ok', timestamp: new Date().toISOString() }))

app.listen(PORT, () => {
  console.log(`TweakShift License Server running on port ${PORT}`)
  if (!GUMROAD_ACCESS_TOKEN) console.warn('⚠ GUMROAD_ACCESS_TOKEN not set in .env')
  if (!GUMROAD_PRODUCT_ID) console.warn('⚠ GUMROAD_PRODUCT_ID not set in .env')
})