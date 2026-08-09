# TweakShift Classic dashboard notifications

The desktop app now reads notifications from:

`GET /api/notifications`

The endpoint reads `notifications.json` from this repository. If this Render service is connected to GitHub with auto-deploy enabled, editing and committing `notifications.json` is enough to publish new notices after the deployment completes.

## Add a notification

Add an object inside the `notifications` array:

```json
{
  "id": "aug-2026-driver-note",
  "title": "Driver Center Update",
  "message": "A new Driver Center build is rolling out this week.",
  "type": "update",
  "active": true,
  "createdAt": "2026-08-09T10:00:00Z",
  "platforms": ["win32"]
}
```

Supported `type` values: `info`, `update`, `bug`, `alert`.

Optional targeting fields:

- `minVersion`: only show on this version or newer.
- `maxVersion`: only show on this version or older.
- `expiresAt`: ISO date/time after which the notice disappears.
- `platforms`: for the Windows app use `["win32"]`; use `["all"]` for every platform.
- `actionUrl`: optional URL reserved for future clickable actions.

Set `active` to `false` to hide a notice without deleting it.

The desktop app refreshes notices at launch, when the app becomes visible again, and every five minutes while open.
