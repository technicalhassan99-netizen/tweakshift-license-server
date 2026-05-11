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
const GUMROAD_PRODUCT_ID = process.env.GUMROAD_PRODUCT_ID
const PAYHIP_PRODUCT_SECRET_KEY = process.env.PAYHIP_PRODUCT_SECRET_KEY
const MAX_DEVICES = parseInt(process.env.MAX_DEVICES_PER_LICENSE) || 2
const PORT = parseInt(process.env.PORT) || 3001
const ACTIVATIONS_FILE = path.join(__dirname, 'activations.json')

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

async function verifyPayhipLicense(cleanKey) {
  if (!PAYHIP_PRODUCT_SECRET_KEY) {
    console.warn('PAYHIP_PRODUCT_SECRET_KEY not set. Skipping Payhip verification.')
    return { valid: false, skipped: true, message: 'PAYHIP_PRODUCT_SECRET_KEY not set.' }
  }

  try {
    console.log('Verifying with Payhip | key:', cleanKey.slice(0, 8) + '...')

    const response = await axios.get(
      'https://payhip.com/api/v2/license/verify',
      {
        params: {
          license_key: cleanKey
        },
        headers: {
          'product-secret-key': PAYHIP_PRODUCT_SECRET_KEY
        },
        timeout: 10000
      }
    )

    console.log('Payhip response:', response.status, JSON.stringify(response.data))

    const licenseData = response.data?.data

    if (licenseData && licenseData.enabled === true) {
      return {
        valid: true,
        provider: 'payhip',
        data: licenseData,
        email: licenseData.buyer_email || null
      }
    }

    return {
      valid: false,
      provider: 'payhip',
      message: 'Invalid or disabled Payhip license.'
    }
  } catch (err) {
    if (err.response) {
      console.log('Payhip HTTP error:', err.response.status, JSON.stringify(err.response.data))
      return {
        valid: false,
        provider: 'payhip',
        message: 'Payhip license rejected.'
      }
    }

    console.error('Payhip network error:', err.message)
    return {
      valid: false,
      provider: 'payhip',
      message: 'Could not reach Payhip.'
    }
  }
}

async function verifyGumroadLicense(cleanKey) {
  if (!GUMROAD_PRODUCT_ID) {
    console.warn('GUMROAD_PRODUCT_ID not set. Skipping Gumroad verification.')
    return { valid: false, skipped: true, message: 'GUMROAD_PRODUCT_ID not set.' }
  }

  try {
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

    if (response.data?.success === true) {
      return {
        valid: true,
        provider: 'gumroad',
        data: response.data,
        email: response.data?.purchase?.email || null
      }
    }

    return {
      valid: false,
      provider: 'gumroad',
      message: response.data?.message || 'Invalid Gumroad license.'
    }
  } catch (err) {
    if (err.response) {
      console.log('Gumroad HTTP error:', err.response.status, JSON.stringify(err.response.data))

      return {
        valid: false,
        provider: 'gumroad',
        message: err.response.data?.message || 'Invalid Gumroad license.'
      }
    }

    console.error('Gumroad network error:', err.message)

    return {
      valid: false,
      provider: 'gumroad',
      networkError: true,
      message: 'Could not reach Gumroad.'
    }
  }
}

function checkAndSaveDeviceActivation(cleanKey, deviceId, provider, email) {
  const activationKey = `${provider}:${cleanKey}`

  const activations = loadActivations()
  const record = activations[activationKey] || {
    provider,
    email: email || null,
    devices: []
  }

  if (record.devices.includes(deviceId)) {
    record.lastSeen = new Date().toISOString()
    record.email = email || record.email || null
    activations[activationKey] = record
    saveActivations(activations)

    return {
      allowed: true,
      reactivation: true,
      deviceCount: record.devices.length
    }
  }

  if (record.devices.length >= MAX_DEVICES) {
    return {
      allowed: false,
      message: `License already used on ${MAX_DEVICES} device(s). Contact support to reset.`
    }
  }

  record.devices.push(deviceId)
  record.provider = provider
  record.email = email || record.email || null
  record.activatedAt = record.activatedAt || new Date().toISOString()
  record.lastSeen = new Date().toISOString()

  activations[activationKey] = record
  saveActivations(activations)

  return {
    allowed: true,
    reactivation: false,
    deviceCount: record.devices.length
  }
}

app.post('/api/verify-license', async (req, res) => {
  const { licenseKey, deviceId } = req.body

  if (!licenseKey || !deviceId) {
    return res.status(400).json({
      success: false,
      message: 'License key and device ID are required.'
    })
  }

  const cleanKey = licenseKey.trim().toUpperCase()

  // 1. New Payhip users check first
  const payhipResult = await verifyPayhipLicense(cleanKey)

  if (payhipResult.valid) {
    const deviceCheck = checkAndSaveDeviceActivation(
      cleanKey,
      deviceId,
      'payhip',
      payhipResult.email
    )

    if (!deviceCheck.allowed) {
      return res.json({
        success: false,
        message: deviceCheck.message
      })
    }

    console.log(
      deviceCheck.reactivation ? 'Payhip re-activation OK:' : 'Payhip new activation:',
      cleanKey.slice(0, 8),
      '| Total devices:',
      deviceCheck.deviceCount
    )

    return res.json({
      success: true,
      provider: 'payhip',
      plan: 'premium',
      premiumUnlocked: true,
      message: 'Payhip license verified successfully.'
    })
  }

  // 2. Old Gumroad users fallback
  const gumroadResult = await verifyGumroadLicense(cleanKey)

  if (gumroadResult.valid) {
    const deviceCheck = checkAndSaveDeviceActivation(
      cleanKey,
      deviceId,
      'gumroad',
      gumroadResult.email
    )

    if (!deviceCheck.allowed) {
      return res.json({
        success: false,
        message: deviceCheck.message
      })
    }

    console.log(
      deviceCheck.reactivation ? 'Gumroad re-activation OK:' : 'Gumroad new activation:',
      cleanKey.slice(0, 8),
      '| Total devices:',
      deviceCheck.deviceCount
    )

    return res.json({
      success: true,
      provider: 'gumroad',
      plan: 'premium',
      premiumUnlocked: true,
      message: 'Gumroad license verified successfully.'
    })
  }

  console.warn('License rejected:', cleanKey.slice(0, 8))

  return res.json({
    success: false,
    message: gumroadResult.message || payhipResult.message || 'Invalid license key. Please check and try again.'
  })
})

app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    gumroad_product_id: GUMROAD_PRODUCT_ID ? 'SET' : 'NOT SET',
    payhip_product_secret_key: PAYHIP_PRODUCT_SECRET_KEY ? 'SET' : 'NOT SET',
    max_devices: MAX_DEVICES,
    activations: Object.keys(inMemoryActivations).length,
    timestamp: new Date().toISOString()
  })
})

app.get('/', (req, res) => {
  res.json({
    name: 'TweakShift License Server',
    status: 'running',
    payhip: PAYHIP_PRODUCT_SECRET_KEY ? 'enabled' : 'not configured',
    gumroad: GUMROAD_PRODUCT_ID ? 'enabled' : 'not configured'
  })
})

app.listen(PORT, () => {
  console.log(`TweakShift License Server on port ${PORT}`)
  console.log(`Gumroad Product ID: ${GUMROAD_PRODUCT_ID ? 'SET' : 'NOT SET'}`)
  console.log(`Payhip Secret Key: ${PAYHIP_PRODUCT_SECRET_KEY ? 'SET' : 'NOT SET'}`)
})
