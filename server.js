/**
 * TweakShift Premium License Server
 * Gumroad stays untouched. Freemius lifetime licensing is added as a second provider.
 * Deploy on Render.com
 * Build: npm install
 * Start: node server.js
 */

const express = require('express')
const cors    = require('cors')
const axios   = require('axios')
const fs      = require('fs')
const path    = require('path')
const crypto  = require('crypto')

require('dotenv').config()

const app = express()
app.use(cors())
app.use(express.json())

const PORT                  = process.env.PORT || 3001
const GUMROAD_PRODUCT_ID    = process.env.GUMROAD_PRODUCT_ID || ''
const GUMROAD_ACCESS_TOKEN  = process.env.GUMROAD_ACCESS_TOKEN || ''
const MAX_DEVICES           = parseInt(process.env.MAX_DEVICES_PER_LICENSE || '1', 10)

// Freemius lifetime product keys. Keep these only in Render Environment Variables.
const FREEMIUS_PRODUCT_ID   = process.env.FREEMIUS_PRODUCT_ID || ''
const FREEMIUS_API_BASE     = 'https://api.freemius.com/v1'

// Local JSON activation DB for Gumroad device binding + basic Freemius install tracking.
// For bigger scale, move this to PostgreSQL/Redis later.
const DB_PATH = path.join(__dirname, 'activations.json')

function loadDB() {
  try {
    if (!fs.existsSync(DB_PATH)) return {}
    return JSON.parse(fs.readFileSync(DB_PATH, 'utf8'))
  } catch {
    return {}
  }
}

function saveDB(data) {
  fs.writeFileSync(DB_PATH, JSON.stringify(data, null, 2), 'utf8')
}

function normalizeKey(licenseKey) {
  return String(licenseKey || '').trim().toUpperCase()
}

function makeFreemiusUid(deviceId) {
  // Freemius requires a stable 32-character UID. This is deterministic per PC/device.
  return crypto.createHash('sha256').update(String(deviceId || 'unknown-device')).digest('hex').slice(0, 32)
}

function getFreemiusInstallId(data) {
  return data?.install?.id || data?.install_id || data?.installId || data?.id || null
}

function getFreemiusLicenseId(data) {
  return data?.license?.id || data?.license_id || data?.licenseId || data?.id || null
}

// ── Gumroad verification: untouched existing behavior ───────────────
async function verifyGumroadLicense(licenseKey) {
  if (!GUMROAD_PRODUCT_ID || !GUMROAD_ACCESS_TOKEN) {
    return { success: false, message: 'Gumroad is not configured on the server.' }
  }

  try {
    const response = await axios.post(
      'https://api.gumroad.com/v2/licenses/verify',
      {
        product_id: GUMROAD_PRODUCT_ID,
        license_key: licenseKey,
        increment_uses_count: false,
      },
      {
        headers: { Authorization: `Bearer ${GUMROAD_ACCESS_TOKEN}` },
        timeout: 10000,
      }
    )

    return response.data
  } catch (err) {
    console.error('[Gumroad] Verify error:', err?.response?.data || err.message)
    return { success: false, message: 'Failed to verify with Gumroad.' }
  }
}

function activateDeviceInLocalDB({ normalizedKey, deviceId, provider, extra = {} }) {
  const db = loadDB()

  if (!db[normalizedKey]) {
    db[normalizedKey] = {
      provider,
      devices: [],
      activatedAt: new Date().toISOString(),
      meta: {},
    }
  }

  const record = db[normalizedKey]
  record.provider = record.provider || provider
  record.meta = { ...(record.meta || {}), ...extra }

  if (record.devices.includes(deviceId)) {
    record.lastVerifiedAt = new Date().toISOString()
    saveDB(db)
    return { ok: true, alreadyActive: true, record }
  }

  if (record.devices.length >= MAX_DEVICES) {
    return {
      ok: false,
      message: `This license is already activated on ${MAX_DEVICES} device(s). Deactivate another device first.`,
    }
  }

  record.devices.push(deviceId)
  record.lastVerifiedAt = new Date().toISOString()
  saveDB(db)
  return { ok: true, alreadyActive: false, record }
}

function deactivateDeviceInLocalDB({ normalizedKey, deviceId }) {
  const db = loadDB()
  if (!db[normalizedKey]) return true
  db[normalizedKey].devices = (db[normalizedKey].devices || []).filter(d => d !== deviceId)
  db[normalizedKey].lastDeactivatedAt = new Date().toISOString()
  saveDB(db)
  return true
}

// ── Freemius lifetime activation ───────────────────────────────────
async function activateFreemiusLicense({ licenseKey, deviceId, deviceName, appVersion }) {
  if (!FREEMIUS_PRODUCT_ID) {
    return { success: false, message: 'Freemius is not configured on the server.' }
  }

  const uid = makeFreemiusUid(deviceId)
  const url = `${FREEMIUS_API_BASE}/products/${FREEMIUS_PRODUCT_ID}/licenses/activate.json`

  try {
    const response = await axios.post(
      url,
      {
        uid,
        license_key: licenseKey,
        title: deviceName || 'TweakShift Premium PC',
        version: appVersion || '1.0.0',
      },
      {
        headers: { 'Content-Type': 'application/json' },
        timeout: 15000,
      }
    )

    const data = response.data || {}
    const installId = getFreemiusInstallId(data)
    const licenseId = getFreemiusLicenseId(data)

    return {
      success: true,
      message: 'Freemius license activated successfully.',
      provider: 'freemius',
      premiumUnlocked: true,
      source: 'freemius',
      freemiusUid: uid,
      freemiusInstallId: installId,
      freemiusLicenseId: licenseId,
      raw: data,
    }
  } catch (err) {
    const data = err?.response?.data
    console.error('[Freemius] Activate error:', data || err.message)
    return {
      success: false,
      message: data?.error?.message || data?.message || 'Invalid Freemius license key.',
      provider: 'freemius',
      details: data || null,
    }
  }
}

async function deactivateFreemiusLicense({ licenseKey, deviceId, freemiusUid, freemiusInstallId }) {
  if (!FREEMIUS_PRODUCT_ID) {
    return { success: false, message: 'Freemius is not configured on the server.' }
  }

  const uid = freemiusUid || makeFreemiusUid(deviceId)
  const installId = freemiusInstallId

  if (!uid || !installId) {
    return { success: false, message: 'Missing Freemius install data. Local license was removed, but Freemius quota may still need manual deactivation.' }
  }

  const url = `${FREEMIUS_API_BASE}/products/${FREEMIUS_PRODUCT_ID}/licenses/deactivate.json?fields=id,name,slug`

  try {
    const response = await axios.post(
      url,
      {
        uid,
        install_id: Number(installId),
        license_key: licenseKey,
      },
      {
        headers: { 'Content-Type': 'application/json' },
        timeout: 15000,
      }
    )

    return {
      success: true,
      message: 'Freemius license deactivated on this PC.',
      provider: 'freemius',
      raw: response.data || {},
    }
  } catch (err) {
    const data = err?.response?.data
    console.error('[Freemius] Deactivate error:', data || err.message)
    return {
      success: false,
      message: data?.error?.message || data?.message || 'Freemius deactivation failed.',
      provider: 'freemius',
      details: data || null,
    }
  }
}

// ── POST /api/verify-license ───────────────────────────────────────
// This endpoint is used by the app Activate button.
// It first verifies existing Gumroad users exactly like before.
// If Gumroad fails, it tries Freemius lifetime activation.
app.post('/api/verify-license', async (req, res) => {
  const { licenseKey, deviceId, deviceName, appVersion } = req.body

  if (!licenseKey || !deviceId) {
    return res.status(400).json({ success: false, message: 'Missing licenseKey or deviceId.' })
  }

  const cleanKey = String(licenseKey).trim()
  const normalizedKey = normalizeKey(cleanKey)

  // 1) Existing Gumroad flow — untouched for current customers.
  const gumroadResult = await verifyGumroadLicense(cleanKey)

  if (gumroadResult?.success) {
    const deviceResult = activateDeviceInLocalDB({
      normalizedKey,
      deviceId,
      provider: 'gumroad',
      extra: { gumroadSaleId: gumroadResult?.purchase?.id || null },
    })

    if (!deviceResult.ok) {
      return res.json({ success: false, message: deviceResult.message })
    }

    return res.json({
      success: true,
      message: deviceResult.alreadyActive ? 'Gumroad license verified successfully.' : 'Gumroad license activated successfully!',
      premiumUnlocked: true,
      provider: 'gumroad',
      source: 'gumroad',
      licenseKey: normalizedKey,
      activatedAt: new Date().toISOString(),
    })
  }

  // 2) New Freemius lifetime flow.
  const freemiusResult = await activateFreemiusLicense({
    licenseKey: cleanKey,
    deviceId,
    deviceName,
    appVersion,
  })

  if (!freemiusResult?.success) {
    return res.json({
      success: false,
      message: freemiusResult?.message || 'Invalid license key. Please check your key and try again.',
      gumroadMessage: gumroadResult?.message || null,
      freemiusDetails: freemiusResult?.details || null,
    })
  }

  const deviceResult = activateDeviceInLocalDB({
    normalizedKey,
    deviceId,
    provider: 'freemius',
    extra: {
      freemiusUid: freemiusResult.freemiusUid,
      freemiusInstallId: freemiusResult.freemiusInstallId,
      freemiusLicenseId: freemiusResult.freemiusLicenseId,
    },
  })

  if (!deviceResult.ok) {
    // Freemius activation succeeded but local quota says too many.
    // Try to release Freemius install immediately to avoid locked quota.
    await deactivateFreemiusLicense({
      licenseKey: cleanKey,
      deviceId,
      freemiusUid: freemiusResult.freemiusUid,
      freemiusInstallId: freemiusResult.freemiusInstallId,
    })
    return res.json({ success: false, message: deviceResult.message })
  }

  return res.json({
    success: true,
    message: 'Freemius license activated successfully.',
    premiumUnlocked: true,
    provider: 'freemius',
    source: 'freemius',
    licenseKey: normalizedKey,
    freemiusUid: freemiusResult.freemiusUid,
    freemiusInstallId: freemiusResult.freemiusInstallId,
    freemiusLicenseId: freemiusResult.freemiusLicenseId,
    activatedAt: new Date().toISOString(),
  })
})

// Alias for future compatibility.
app.post('/api/license/activate', (req, res) => {
  req.url = '/api/verify-license'
  app._router.handle(req, res)
})

// ── POST /api/deactivate-license ───────────────────────────────────
// Gumroad: removes device from local DB only.
// Freemius: releases Freemius install quota, then removes device from local DB.
app.post('/api/deactivate-license', async (req, res) => {
  const { licenseKey, deviceId, source, freemiusUid, freemiusInstallId } = req.body

  if (!licenseKey || !deviceId) {
    return res.status(400).json({ success: false, message: 'Missing licenseKey or deviceId.' })
  }

  const normalizedKey = normalizeKey(licenseKey)
  const db = loadDB()
  const record = db[normalizedKey]
  const provider = source || record?.provider || 'gumroad'

  let remoteResult = { success: true, message: 'License deactivated on this device.' }

  if (provider === 'freemius') {
    remoteResult = await deactivateFreemiusLicense({
      licenseKey: String(licenseKey).trim(),
      deviceId,
      freemiusUid: freemiusUid || record?.meta?.freemiusUid,
      freemiusInstallId: freemiusInstallId || record?.meta?.freemiusInstallId,
    })
  }

  deactivateDeviceInLocalDB({ normalizedKey, deviceId })

  return res.json({
    success: remoteResult.success !== false,
    message: remoteResult.message || 'License deactivated on this device.',
    provider,
    remote: remoteResult,
  })
})

// Future-compatible alias.
app.post('/api/license/deactivate', (req, res) => {
  req.url = '/api/deactivate-license'
  app._router.handle(req, res)
})

// ── GET /api/health ────────────────────────────────────────────────
app.get('/api/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'TweakShift Premium License Server',
    gumroadConfigured: Boolean(GUMROAD_PRODUCT_ID && GUMROAD_ACCESS_TOKEN),
    freemiusConfigured: Boolean(FREEMIUS_PRODUCT_ID),
    timestamp: new Date().toISOString(),
  })
})

app.get('/', (req, res) => {
  res.json({ ok: true, service: 'TweakShift Premium License Server' })
})

app.listen(PORT, () => {
  console.log(`[TweakShift Premium License Server] Running on port ${PORT}`)
  console.log(`[TweakShift Premium License Server] Gumroad: ${GUMROAD_PRODUCT_ID ? 'configured' : 'NOT SET'}`)
  console.log(`[TweakShift Premium License Server] Freemius: ${FREEMIUS_PRODUCT_ID ? 'configured' : 'NOT SET'}`)
})
