
## Phone App

- Open the passenger home page in Chrome on Android and use **Install TrackFare** when the browser offers it.
- Installation and service-worker support require HTTPS (or `localhost`). The current LAN address over HTTP can still use the tap buttons, but it cannot install the PWA.
- The passenger home page supports authenticated Tap In and Tap Out actions. Presenting the phone itself as a contactless card to the bus reader still requires a native Android HCE app.
- If passenger Tap Out reports **TRANSACTION FAILED**, apply `migrations/20261005_passenger_fare_splits.sql` to the `trackfare` database in phpMyAdmin. Tap-out records the driver's fare share in `fare_splits`; the fare transaction is rolled back if this table or the driver's wallet column is missing.

- The ESP32 and passenger app must use the same XAMPP host and database. The ESP32 host is configured once as `TRACKFARE_SERVER_HOST` in `esp.ino`; it currently uses `192.168.1.67`, this computer's Wi-Fi address. Reserve that address in the router so it does not change.
- For ESP32/phone access, run PowerShell as Administrator and allow Apache on the trusted Private LAN only:

  ```powershell
  New-NetFirewallRule -DisplayName "TrackFare XAMPP Apache (Private LAN)" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80 -Profile Private -RemoteAddress LocalSubnet
  ```

- Keep Apache and MySQL running, connect the ESP32 and phone to the same non-isolated Wi-Fi network, and use the same XAMPP host in the passenger app. A weak Wi-Fi signal (for example, around `-81 dBm`) can still cause intermittent taps; move the ESP32 closer to the router or use a stronger access point.
- Chrome/Edge updated or reset location permissions.
- The passenger is using a different browser/device/network.
- The browser cached an earlier permission, but now correctly reports the IP site as insecure.

`window.isSecureContext === false` confirms live GPS is currently blocked. The font warning is unrelated.

For immediate testing:

1. On the passenger device, open:
   `chrome://flags/#unsafely-treat-insecure-origin-as-secure`
2. Enable the setting.
3. Add:
   `http://192.168.1.67`
4. Restart Chrome.
5. Open the passenger page and choose **Allow** for Location.
6. Tap in again, then refresh the driver page.

The permanent fix is to use HTTPS for the LAN site. The fallback should still place the passenger at the boarding stop, even without live GPS.




**Complete PayMongo GCash Setup**

1. **Regenerate your exposed secret key**
   - PayMongo Dashboard → **Developers → API Keys**
   - Click **Regenerate** under Secret Key.
   - Copy the new key beginning with `sk_test_`.
   - Do not share it.

2. **Configure Apache**
   - Open:

   ```text
   C:\xampp\apache\conf\httpd.conf
   ```

   - Add:

   ```apache
   SetEnv PAYMONGO_SECRET_KEY "sk_test_YOUR_NEW_KEY"
   SetEnv PAYMONGO_API_BASE_URL "https://api.paymongo.com/v2"
   SetEnv TRACKFARE_GCASH_SUCCESS_URL "http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=success"
   SetEnv TRACKFARE_GCASH_FAILURE_URL "http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=failed"
   ```

   - Save and restart Apache in XAMPP.

3. **Start ngrok**
   - Make sure Apache is running.
   - Open PowerShell:

   ```powershell
   ngrok http 80
   ```

   - Copy the HTTPS forwarding address, such as:

   ```text
   https://abc123.ngrok-free.app
   ```

4. **Create the PayMongo webhook**
   - PayMongo Dashboard → **Settings → Webhooks**
   - Click **Create/Add webhook**.
   - Endpoint URL:

   ```text
   https://abc123.ngrok-free.app/trackfare/api/gcash_webhook.php
   ```

   - Replace the domain with your actual ngrok domain.
   - Select only:

   ```text
   checkout_session.payment.paid
   ```

   - Create the webhook.
   - Copy the generated webhook secret beginning with `whsec_`.

5. **Add the webhook secret**
   - Add this to `httpd.conf`:

   ```apache
   SetEnv PAYMONGO_WEBHOOK_SECRET "whsec_YOUR_WEBHOOK_SECRET"
   ```

   - Save and restart Apache again.

6. **Test TrackFare**
   - Open:

   ```text
   http://localhost/trackfare/auth/login.php
   ```

   - Log in as a passenger.
   - Open **Wallet**.
   - Enter a small amount.
   - Click **Top up with GCash**.
   - Complete the payment in PayMongo Test Mode.

7. **Verify**
   - Check PayMongo webhook delivery logs.
   - The webhook should return HTTP `200`.
   - Refresh the TrackFare wallet.
   - The top-up should change from `PENDING` to `COMPLETED`.
   - The balance should increase only once.

Keep ngrok running during testing. The project setup guide is also available at `PAYMONGO_SETUP.md`.

Follow these steps exactly.

**1. Regenerate your PayMongo secrets**

In PayMongo Dashboard:

1. Open **Developers → API keys**.
2. Regenerate your **Test Secret Key**.
3. Copy the new key beginning with `sk_test_`.
4. Open **Developers → Webhooks**.
5. Create or open your webhook endpoint.
6. Copy the webhook secret beginning with `whsec_`.

Do not send either secret here.

**2. Add the webhook secret to Apache**

Open:

```text
C:\xampp\apache\conf\httpd.conf
```

Add this line near your other PayMongo settings:

```apache
SetEnv PAYMONGO_WEBHOOK_SECRET "whsec_PASTE_YOUR_SECRET_HERE"
```

Keep your other settings:

```apache
SetEnv PAYMONGO_API_BASE_URL "https://api.paymongo.com/v2"
SetEnv TRACKFARE_GCASH_SUCCESS_URL "http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=success"
SetEnv TRACKFARE_GCASH_FAILURE_URL "http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=failed"
```

Save the file.

**3. Restart Apache**

1. Open the XAMPP Control Panel.
2. Click **Stop** beside Apache.
3. Wait until it stops.
4. Click **Start** again.

This is required because Apache only loads `SetEnv` values when it starts.

**4. Start ngrok**

Open PowerShell and run:

```powershell
ngrok http 80
```

You will see an HTTPS address similar to:

```text
https://abc123.ngrok-free.app
```

Keep this PowerShell window open.

**5. Configure the PayMongo webhook**

In PayMongo Dashboard:

1. Go to **Developers → Webhooks**.
2. Click **Add endpoint**.
3. Enter:

```text
https://abc123.ngrok-free.app/trackfare/api/gcash_webhook.php
```

Replace `abc123.ngrok-free.app` with your actual ngrok address.

4. Select this event:

```text
checkout_session.payment.paid
```

5. Save the endpoint.
6. Copy its webhook secret and place it in Apache as shown in step 2.
7. Restart Apache again.

**6. Test a new top-up**

1. Log in to TrackFare.
2. Open **Wallet**.
3. Click `₱50`, `₱100`, or `₱200`.
4. Complete the GCash payment on PayMongo.
5. Open PayMongo **Webhooks → Delivery logs**.
6. Confirm the delivery response is HTTP `200`.
7. Refresh the TrackFare Wallet page.

The status should change from `PENDING` to `COMPLETED`, and the balance should increase.

**7. Process the old pending payment**

In the PayMongo webhook delivery log, open the successful payment event and click **Retry/Resend**. Then refresh the Wallet page.

The current webhook code is in `gcash_webhook.php`, and the wallet checkout is in `03_wallet.php`.



ngrok requires authentication before it can create the public HTTPS tunnel.

1. Open: https://dashboard.ngrok.com/signup  
2. Create or log in to your ngrok account.
3. Open: https://dashboard.ngrok.com/get-started/your-authtoken
4. Copy your authtoken.
5. In PowerShell, run:

```powershell
ngrok config add-authtoken YOUR_NGROK_AUTHTOKEN
```

Replace `YOUR_NGROK_AUTHTOKEN` with your actual token. Do not send it here.

Then start the tunnel:

```powershell
ngrok http 80
```

You should get an HTTPS URL like:

```text
https://abc123.ngrok-free.app
```

Use this as your PayMongo webhook URL:

```text
https://abc123.ngrok-free.app/trackfare/api/gcash_webhook.php
```

Keep ngrok running while testing payments.

Your ngrok tunnel is working.

Use this PayMongo webhook URL:

```text
https://pasty-unwoven-twitch.ngrok-free.dev/trackfare/api/gcash_webhook.php
```

In PayMongo:

1. Go to **Developers → Webhooks**.
2. Create or edit your webhook.
3. Set the endpoint URL above.
4. Subscribe to:

```text
checkout_session.payment.paid
```

5. Copy the generated `whsec_...` signing secret.
6. Add it to `httpd.conf`:

```apache
SetEnv PAYMONGO_WEBHOOK_SECRET "whsec_YOUR_SECRET"
```

7. Restart Apache.
8. Keep ngrok running.
9. Make a new GCash top-up and complete payment.

Check `http://127.0.0.1:4040` to see whether PayMongo reaches your webhook. A successful request should return HTTP `200`.

The ESP32 network host, tap endpoints, and device token are configured in the current root `esp.ino` firmware. Do not copy old credentials or device tokens from archived examples.