# TrackFare GCash with PayMongo

## 1. Create a PayMongo test account

1. Open `https://dashboard.paymongo.com/` and create or sign in to your account.
2. Switch the dashboard to **Test mode**.
3. Open **Developers -> API keys** and copy the **test secret key**. It starts with `sk_test_`.
4. Do not put that key in a PHP file, JavaScript file, Git commit, or screenshot.

## 2. Set the XAMPP environment variables

Copy `.env.example` as a private reference and replace the placeholders with your real values. The file is only a template; PHP does not automatically load `.env` files.

For XAMPP, add these lines to the Apache virtual host that serves TrackFare, or to an allowed `.htaccess` file:

```apache
SetEnv PAYMONGO_SECRET_KEY "sk_test_your_real_key"
SetEnv PAYMONGO_WEBHOOK_SECRET "whsec_your_real_webhook_secret"
SetEnv PAYMONGO_API_BASE_URL "https://api.paymongo.com/v2"
SetEnv TRACKFARE_GCASH_SUCCESS_URL "http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=success"
SetEnv TRACKFARE_GCASH_FAILURE_URL "http://localhost/trackfare/user/01_passenger/03_wallet.php?gcash=failed"
```

If you edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, put the lines inside the `<VirtualHost>` block serving `c:/xampp/htdocs`. Restart Apache after saving. Never commit the real values.

## 3. Expose the webhook during local testing

PayMongo cannot call `http://localhost`. Use a tunnel such as ngrok:

1. Install ngrok from `https://ngrok.com/` and sign in.
2. Start Apache and confirm TrackFare opens at `http://localhost/trackfare/`.
3. Run `ngrok http 80`.
4. Copy the HTTPS forwarding URL, for example `https://abc123.ngrok-free.app`.
5. Your webhook URL is:
   `https://abc123.ngrok-free.app/trackfare/api/gcash_webhook.php`

Keep the tunnel running while testing. Its URL changes when the tunnel is restarted unless you have a reserved domain.

## 4. Register the PayMongo webhook

1. In PayMongo Test mode, open **Developers -> Webhooks**.
2. Click **Add endpoint**.
3. Paste the HTTPS webhook URL from step 3.
4. Subscribe to `checkout_session.payment.paid`.
5. Save the endpoint.
6. Copy the webhook secret shown by PayMongo into `PAYMONGO_WEBHOOK_SECRET`.
7. Restart Apache after changing the environment variable.

The webhook is the source of truth for crediting the wallet. Returning to the success URL alone does not credit the wallet.

## 5. Test a top-up

1. Open `http://localhost/trackfare/auth/login.php`.
2. Sign in as a passenger, for example `aaron@gmail.com` with the seed password `password`.
3. Open the passenger **Wallet** page.
4. Enter an amount such as `50` and a test GCash number when PayMongo asks for one.
5. Click **Top up with GCash**. TrackFare creates a PayMongo Checkout Session and redirects to the hosted PayMongo page.
6. Complete the payment using PayMongo's current test-mode GCash instructions.
7. Check the PayMongo webhook delivery log. It must show HTTP 2xx.
8. Refresh the TrackFare Wallet page. The pending top-up should become `COMPLETED` and the balance should increase exactly once.

## 6. Troubleshooting

- `GCash is not configured yet`: Apache was not restarted, or `PAYMONGO_SECRET_KEY` is not visible to PHP.
- PayMongo rejects the request: confirm the key is a test secret key, GCash is enabled for the account, and the API base URL is `https://api.paymongo.com/v2`.
- Webhook returns `401`: copy the endpoint secret exactly and verify the `Paymongo-Signature` header is reaching Apache.
- Webhook returns `404`: the payment reference does not match a pending TrackFare top-up; check that the same database is used by Apache and MySQL.
- PayMongo cannot reach the endpoint: use the HTTPS tunnel URL, not localhost, and keep the tunnel running.
- A payment succeeds but the wallet remains pending: inspect the webhook delivery log and PHP/Apache error log before retrying the event.

## 7. Before going live

1. Replace the test secret and test webhook endpoint with separate live-mode credentials and a stable HTTPS domain.
2. Create a separate live webhook endpoint and subscribe only to `checkout_session.payment.paid`.
3. Update the success and failure URLs to the production domain.
4. Test one small live payment and reconcile it in the PayMongo dashboard before enabling normal use.
