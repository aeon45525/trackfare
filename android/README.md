# TrackFare Passenger for Android

The APK embeds the existing TrackFare PHP passenger pages inside the app, so login, Home/tap, Routes, Wallet, Trips, and Profile use the existing website code without launching an external browser. Phone NFC card emulation is handled by a native Android HCE service.

## Connect to the local website

The website does not need to be deployed. Start Apache and MySQL in XAMPP on the computer running TrackFare, then open the app's **Server** setting:

- Physical Android phone: use `http://192.168.1.20/TrackFare/` on the same Wi-Fi as the ESP32; the app migrates a previously saved `192.168.1.56` setting to this host. The Android Emulator can use `http://10.0.2.2/trackfare/` from the Server setting.
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
