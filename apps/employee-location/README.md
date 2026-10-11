# RailTime employee location companions

These two native companions collect location only. The employee UI, login-session consent, device administration and time recording remain in the existing RailTime PWA. The server integration is disabled initially.

The Android project is a Kotlin application with a native location foreground service; the iOS project is a SwiftUI application with Core Location and a background activity session. Minimum supported versions: Android 8 / API 26 and iOS 17. Application identifier on both platforms: `de.railtime.location`.

## Employee setup

1. Install the RailTime PWA and the signed companion on the assigned business phone.
2. Under **Mein Arbeitstag**, create the one-use device registration code. It lasts 15 minutes and is tied to the existing corporate device assignment.
3. In the companion, enter the exact HTTPS RailTime origin and that code. Only this origin is paired; cross-origin redirects are rejected. Credentials are stored using Android Keystore encryption or iOS Keychain, without cloud/device transfer backup.
4. Start time recording and explicitly permit the work-location purpose for the active login session in the PWA. Open **Standortdienst starten**. The verified link carries a one-use 60-second start code in its fragment, never in its query string or a server log.
5. Press **Standortdienst sichtbar starten** in the companion. Allow precise location and the Android ongoing notification. If the operating system permission dialog consumes the start-code lifetime, obtain a new code in the PWA.
6. **Standortdienst beenden** stops the native session without altering work time. **Sitzungsfreigabe verwalten / widerrufen** opens the PWA. Pause, work end, logout, login-session expiry, revoked consent or an invalid device assignment prevent the server from renewing the session. A new visible start is required after interruption.

If an installed application does not receive the verified link yet, copy only the start code from the PWA into the companion's start-code input. Do not send credentials to another device or paste a RailTime account password into the companion.

## Capture and expiry

- Location consent remains valid for the active login session and can be changed or withdrawn in the employee's own profile. It does not expire at midnight. Normal app usage can renew the authenticated session; the native service cannot do so. Logout or session expiry prevents further collection and routing use immediately on the server.
- A permit lasts at most 120 seconds. The request roundtrip is deducted from the effective deadline; its deadline uses a monotonic clock that includes sleep. The companion requests renewal approximately every 60 seconds and checks expiry in each fix callback and before upload.
- Only a fresh fix with measured accuracy at most 50 metres is accepted. Fixes older than 30 seconds, captured before the authorized sample window, outside coordinate bounds or after expiry are discarded.
- The first authorized point is attempted immediately; later points follow the server's 900-second cadence. Intermediate OS fixes are discarded. The sample time is derived from server time and the relative age of the fix, preventing device wall-clock changes from extending authorization.
- There is no coordinate database, file, background upload queue or offline retry. A transport failure stops the session. Registration remains stored so the employee can explicitly start again.
- If a point is due but the fix is inaccurate or unavailable, the companion reports only that quality status at most once per minute. The PWA can display the gap without receiving the rejected coordinates. A quality report never advances the 15-minute sample window; the companion continues seeking a precise fix while authorized.
- The app does not restart collection after boot, force quit or process death. A suspended OS can defer callbacks; no guarantee of exact physical sensor shutdown or exact 15-minute collection is made. Server authorization remains authoritative.
- Android displays an ongoing notification with a stop action. iOS requests only user-visible session authorization and keeps the system background indicator visible. No broad background permission or always-on tracking is requested for automatic restarts.

## Android build

Requirements: JDK 17, Android SDK platform 35 and standard SDK build tools. The checked-in Gradle 8.9 wrapper verifies its distribution SHA-256.

```powershell
cd apps/employee-location/android
./gradlew.bat --no-daemon testDebugUnitTest lintDebug assembleDebug
./gradlew.bat --no-daemon assembleRelease -PRAILTIME_APP_LINK_HOST=your.production.host
```

On macOS/Linux use `./gradlew`; make it executable if the checkout did not retain executable mode. A debug APK is generated at `app/build/outputs/apk/debug/app-debug.apk`. A release build is unsigned unless a release signing configuration is supplied through the build environment. Do not distribute the debug APK as a production release.

For release signing set `RAILTIME_ANDROID_KEYSTORE`, `RAILTIME_ANDROID_STORE_PASSWORD`, `RAILTIME_ANDROID_KEY_ALIAS` and `RAILTIME_ANDROID_KEY_PASSWORD` in the local/CI environment. Do not put these values in Gradle command arguments, checked-in properties or RailTime's settings. The signing certificate's public SHA-256 fingerprint belongs in **Mitarbeiter-Standorte**. The HTTPS origin must serve `/.well-known/assetlinks.json` with that fingerprint and the actual package ID.

## iOS build and tests

Open `ios/RailTimeLocation.xcodeproj` in Xcode 16 or later. The shared `RailTimeLocation` scheme contains the app and `PermitPolicyTests`. Set the actual production host and Apple development team in build settings; `railtime.invalid` deliberately prevents accidental domain activation.

```bash
cd apps/employee-location/ios
xcodebuild -project RailTimeLocation.xcodeproj -scheme RailTimeLocation \
  -configuration Debug -destination 'generic/platform=iOS Simulator' \
  CODE_SIGNING_ALLOWED=NO build

# Choose an installed simulator using `xcrun simctl list devices available`.
xcodebuild -project RailTimeLocation.xcodeproj -scheme RailTimeLocation \
  -destination 'platform=iOS Simulator,name=iPhone 16' \
  CODE_SIGNING_ALLOWED=NO test

xcodebuild -project RailTimeLocation.xcodeproj -scheme RailTimeLocation \
  -configuration Release -destination 'generic/platform=iOS' \
  RAILTIME_APP_LINK_HOST=your.production.host DEVELOPMENT_TEAM=YOUR_TEAM_ID \
  -archivePath build/RailTimeLocation.xcarchive archive
```

Use the approved corporate distribution method to sign/export/install the archive. The server setting `ios_app_id` is the Apple team prefix plus bundle ID, e.g. `TEAMID1234.de.railtime.location`. Serve `/.well-known/apple-app-site-association` directly over HTTPS with the matching App ID and `/employee-location/open` path. Configure the same host in `Config.xcconfig` or through `xcodebuild`; the associated-domain entitlement is part of the project.

The included privacy manifest declares linked precise location and installation ID for app functionality, and monotonic timing for local timers/event timestamps. It declares no advertising tracking. Review the final privacy report against the actual deployment before corporate distribution.

`tools/generate_xcode_project.py` regenerates the checked-in project and scheme deterministically from the existing app/test source list. `tools/validate_projects.py` validates XML/plists and source/reference consistency on Windows as well; it does not compile Swift or prove background behavior.

## Verification boundary

During implementation the Android APK, policy unit tests and Android lint were executed using a workspace-local JDK/SDK/Gradle toolchain. The iOS project and plist/reference structure were validated on Windows. iOS compilation, signing, actual App Link / Universal Link verification and a full locked-screen shift on real Android/iPhone devices require the appropriate Mac, signing identities, HTTPS deployment and phones. The web setting must remain disabled until that acceptance is complete.

Useful primary references: [Android foreground location](https://developer.android.com/develop/sensors-and-location/location/permissions), [Android foreground service type](https://developer.android.com/develop/background-work/services/fgs/service-types#location), [Apple background location](https://developer.apple.com/documentation/corelocation/handling-location-updates-in-the-background), [Apple background indicator](https://developer.apple.com/documentation/corelocation/cllocationmanager/showsbackgroundlocationindicator).
