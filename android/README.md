# TrackFare Passenger for Android

The APK embeds the existing TrackFare PHP passenger pages inside the app, so login, Home/tap, Routes, Wallet, Trips, and Profile use the existing website code without launching an external browser. Phone NFC card emulation is handled by a native Android HCE service.

## Connect to the local website

The website does not need to be deployed. Start Apache and MySQL in XAMPP on the computer running TrackFare, then open the app's **Server** setting:

- Physical Android phone: use the XAMPP computer's current Wi-Fi IPv4 address on the same Wi-Fi as the ESP32 (currently `http://192.168.1.67/TrackFare/`). This host must match the one configured in `esp.ino`. The Android Emulator can use `http://10.0.2.2/trackfare/` from the Server setting.
- Both the phone app and ESP32 must reach the same XAMPP host and TrackFare database. Allow Apache through Windows Firewall if prompted.

Sign in with a passenger account. Keep Apache and MySQL running while using the app; the existing passenger pages and phone-NFC registration are served by that local XAMPP instance. The APK download link on the passenger home page saves the package to Android Downloads.

## Link phone NFC

Enable NFC on the phone, sign in as a passenger, then press **Link phone NFC** in the app header. The app creates a P-256 signing key in Android Keystore and registers its public key with `api/register_phone_nfc.php` using the current passenger session. Private key material remains on the phone. NFC taps use the APDU and AID protocol already implemented by `esp.ino` and are verified by `api/phone_nfc_tap.php`.

If the database account cannot create tables, import `migrations/20260928_phone_nfc.sql` in phpMyAdmin. A passenger needs an active fare card linked to their account. Phone NFC requires an Android device with NFC and HCE support, with NFC enabled and the device unlocked.

## Build

Run the **Build TrackFare Android APK** task or build from the workspace root:

```powershell
$env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
$env:ANDROID_SDK_ROOT = $env:ANDROID_HOME
& "$env:LOCALAPPDATA\TrackFareAndroidTools\gradle-8.9\bin\gradle.bat" --no-daemon -p android assembleDebug
```

The debug APK is written to `android/app/build/outputs/apk/debug/app-debug.apk`.

## Scan to Pay

Scan to Pay is available in the passenger Wallet inside the Android app. It uses the native CameraX preview with ML Kit QR scanning; camera access is requested when scanning starts. A passenger can allow access, retry after denial, or open Android app settings if access is blocked. The web page does not use browser camera APIs.

Apply `migrations/20261001_wallet_qr_payments.sql` to the TrackFare database before using the feature. Payment QR payloads must match this exact versioned form, with a 32-character lowercase hexadecimal random request ID:

```text
trackfare://pay?v=1&r=0123456789abcdef0123456789abcdef
```

The QR contains no merchant or amount data. A trusted server-side PHP caller creates the request with `create_trackfare_payment_request()` from `config/payment_request.php`; the function returns the QR payload. For a fixed amount, pass an amount such as `25.00`. For a customer-entered amount, pass `null` for the amount and provide minimum and maximum amounts. Set an expiry timestamp, and distribute the resulting QR only to the intended merchant or fare collection point. The database stores only a SHA-256 hash of the random request ID.

The Android app fetches request details from `api/payment_request.php` and confirms through `api/confirm_payment.php`. The confirm endpoint verifies the request state, expiry, currency, amount, and wallet balance on the server, then records the debit and marks the request paid in one InnoDB transaction. A payment request can be paid only once; a repeated request with the same idempotency key returns the original result. This flow never calls PayMongo.

For device testing, apply the migration, sign in to the same XAMPP server in the Android app, create a short-lived payment request from trusted server-side code, and render the returned payload as a QR code. Test an allowed and denied camera permission, blocked permission via app settings, flashlight on/off, a valid request, QR Ph/EMVCo and malformed codes, expired/already-paid requests, insufficient balance, and a network interruption during confirmation. Confirm each successful scan creates exactly one `wallet_payment_transactions` row and reduces the passenger wallet by the server-stored amount.
