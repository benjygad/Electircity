# SEMS Consumer Mobile App (Flutter)

Consumer application of the Smart Electricity Management System — an
**academic prototype** for Tanzania. It connects to the same PHP REST API as
the admin dashboard. All meter data shown by this app is **simulated**;
token purchases are **records only** and are never loaded onto a physical
LUKU meter.

## Requirements

- Flutter SDK 3.22+ (Dart 3.3+)
- A reachable SEMS backend (see root README)

## Configure the API URL

The base URL is injected at build time via `--dart-define`:

```bash
# Android emulator → host machine:
flutter run --dart-define=SEMS_API_URL=http://10.0.2.2:8000/api/v1

# Physical device → your computer's LAN IP:
flutter run --dart-define=SEMS_API_URL=http://192.168.x.x:8000/api/v1
```

Default (no flag) is `http://10.0.2.2:8000/api/v1`. The value lives in
`lib/core/session.dart` (`SessionStore.api`).

## Run

```bash
cd mobile_app
flutter pub get
flutter run --dart-define=SEMS_API_URL=http://10.0.2.2:8000/api/v1
```

Demo consumer account after seeding: `neema@sems.test / Consumer@123`.

## Structure

```
lib/
  main.dart                  app entry + auth gate
  core/
    api_client.dart          REST client (JSON envelope, bearer token)
    session.dart             secure-storage session (ChangeNotifier)
    theme.dart               Material 3 electricity theme
  models/models.dart         API models
  screens/                   login, register, forgot-password,
                             home shell + 5 tabs, meter detail, notifications
  widgets/common.dart        shared UI (chips, banners, stat cards…)
```

## Security notes

- The bearer token is stored with `flutter_secure_storage`
  (Android Keystore / iOS Keychain), not plain preferences.
- Full electricity tokens are never stored: the server keeps only a masked
  reference (`****-****-1234`).
- All authorization (meter ownership, role checks) is enforced server-side.

## Simulated-data honesty

- The home banner labels the data as simulated.
- Meter screens show the integration status (`Simulated`, `Connected`,
  `Offline`, `Integration unavailable`) and the freshness of the latest reading.
- Recording a token purchase displays: *“This records a SIMULATED purchase…
  No token is sent to a physical LUKU meter.”*
- No smart-home device controls are implemented; a future device layer would
  be added behind the same gateway abstraction and must be clearly labelled.
