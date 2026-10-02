package com.trackfare.phone

import android.Manifest
import android.app.Activity
import android.app.AlertDialog
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.nfc.NfcAdapter
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.Settings
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.view.Gravity
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.GeolocationPermissions
import android.webkit.JavascriptInterface
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.spec.ECGenParameterSpec
import java.util.UUID
import java.util.concurrent.Executors
import org.json.JSONObject

class MainActivity : Activity() {
    private lateinit var webView: WebView
    private lateinit var nfcButton: Button
    private lateinit var serverLabel: TextView
    private val executor = Executors.newSingleThreadExecutor()
    private var pendingDownloadUrl: String? = null
    private var pendingGeolocationOrigin: String? = null
    private var pendingGeolocationCallback: GeolocationPermissions.Callback? = null
    private var nfcLinkInProgress = false
    private var autoRelinkAttempted = false
    private var pendingBusQr: Pair<Int, Int>? = null

    private val preferences by lazy { PhoneNfcStore.preferences(this) }
    private val serverUrl: String
        get() = preferences.getString(PhoneNfcStore.SERVER_URL, PhoneNfcStore.DEFAULT_SERVER_URL)
            ?: PhoneNfcStore.DEFAULT_SERVER_URL

    private fun startQrScanner() {
        val pageHost = runCatching { Uri.parse(webView.url).host }.getOrNull()
        val configuredHost = runCatching { Uri.parse(serverUrl).host }.getOrNull()
        if (pageHost != null && pageHost == configuredHost) {
            startActivityForResult(
                Intent(this, PaymentQrScannerActivity::class.java),
                PAYMENT_QR_SCAN_REQUEST,
            )
        }
    }

    private fun dispatchBusQrTap(busQr: Pair<Int, Int>) {
        webView.evaluateJavascript(
            "window.dispatchEvent(new CustomEvent('trackfare:bus-qr',{detail:{busId:${busQr.first},tripId:${busQr.second}}}));",
            null,
        )
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        migrateServerUrl()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) window.setDecorFitsSystemWindows(true)
        window.statusBarColor = 0xFF0040A1.toInt()
        window.navigationBarColor = 0xFF101820.toInt()

        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setBackgroundColor(0xFFF4F6F7.toInt())
        }
        root.addView(createToolbar(), LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            dp(48),
        ))

        webView = WebView(this).apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.loadsImagesAutomatically = true
            settings.cacheMode = android.webkit.WebSettings.LOAD_NO_CACHE
            settings.javaScriptCanOpenWindowsAutomatically = false
            settings.setGeolocationEnabled(true)
            addJavascriptInterface(object {
                @JavascriptInterface
                fun scanPaymentQr() {
                    runOnUiThread { startQrScanner() }
                }

                @JavascriptInterface
                fun scanBusQr() {
                    runOnUiThread { startQrScanner() }
                }
            }, "TrackFareAndroid")
            webChromeClient = object : WebChromeClient() {
                override fun onGeolocationPermissionsShowPrompt(
                    origin: String?,
                    callback: GeolocationPermissions.Callback?,
                ) {
                    val hasLocationPermission = checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
                        || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
                    if (hasLocationPermission) {
                        callback?.invoke(origin, true, false)
                        return
                    }
                    pendingGeolocationOrigin = origin
                    pendingGeolocationCallback = callback
                    requestPermissions(
                        arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION),
                        GEOLOCATION_PERMISSION_REQUEST,
                    )
                }
            }
            webViewClient = object : WebViewClient() {
                override fun onPageFinished(view: WebView, url: String) {
                    super.onPageFinished(view, url)
                    val path = Uri.parse(url).path.orEmpty()
                    if (path.endsWith("/user/01_passenger/01_home.php")) {
                        pendingBusQr?.let { busQr ->
                            pendingBusQr = null
                            dispatchBusQrTap(busQr)
                        }
                    } else if (path.endsWith("/auth/login.php") && pendingBusQr != null) {
                        pendingBusQr = null
                        Toast.makeText(
                            this@MainActivity,
                            "Sign in as a passenger before scanning the bus QR.",
                            Toast.LENGTH_LONG,
                        ).show()
                    }
                    if (path.endsWith("/auth/login.php")) {
                        autoRelinkAttempted = false
                        preferences.edit()
                            .remove(PhoneNfcStore.CREDENTIAL_ID)
                            .remove(PhoneNfcStore.PENDING_ALIAS)
                            .apply()
                        refreshNfcButton()
                    }
                    if (!autoRelinkAttempted && path.contains("/user/01_passenger/")) {
                        autoRelinkAttempted = true
                        if (NfcAdapter.getDefaultAdapter(this@MainActivity) == null) {
                            Toast.makeText(this@MainActivity, "This phone does not support NFC.", Toast.LENGTH_LONG).show()
                        } else {
                            relinkPhoneNfc()
                        }
                    }
                }

                override fun onReceivedError(
                    view: WebView,
                    request: WebResourceRequest,
                    error: WebResourceError,
                ) {
                    if (request.isForMainFrame) {
                        Toast.makeText(
                            this@MainActivity,
                            "Can't reach TrackFare. Check the server address and local network.",
                            Toast.LENGTH_LONG,
                        ).show()
                    }
                }
            }
            setDownloadListener { url, _, contentDisposition, mimeType, _ ->
                downloadFile(url, contentDisposition, mimeType)
            }
        }
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(webView, true)
        }
        root.addView(webView, LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            0,
            1f,
        ))
        setContentView(root)

        if (savedInstanceState == null) {
            loadPassengerSite()
        } else {
            webView.restoreState(savedInstanceState)
        }
        refreshNfcButton()
    }

    override fun onResume() {
        super.onResume()
        if (::nfcButton.isInitialized) refreshNfcButton()
        if (::webView.isInitialized) {
            webView.evaluateJavascript("window.trackFareRefreshPassengerStatus && window.trackFareRefreshPassengerStatus();", null)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        webView.saveState(outState)
        super.onSaveInstanceState(outState)
    }

    @Deprecated("Deprecated in Android API Activity 1.2; retained for scanner result compatibility")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != PAYMENT_QR_SCAN_REQUEST || resultCode != RESULT_OK) return

        val payload = data?.getStringExtra(PaymentQrScannerActivity.EXTRA_PAYLOAD).orEmpty()
        val busQr = TrackFarePaymentQr.parseBusTap(payload)
        if (busQr != null) {
            val currentPath = Uri.parse(webView.url).path.orEmpty()
            if (currentPath.endsWith("/user/01_passenger/01_home.php")) {
                dispatchBusQrTap(busQr)
            } else {
                pendingBusQr = busQr
                webView.loadUrl(serverUrl.trimEnd('/') + "/user/01_passenger/01_home.php")
            }
            return
        }
        val requestId = TrackFarePaymentQr.parseRequestId(payload) ?: return
        val quotedRequestId = JSONObject.quote(requestId)
        webView.evaluateJavascript(
            "window.dispatchEvent(new CustomEvent('trackfare:payment-qr',{detail:{requestId:$quotedRequestId}}));",
            null,
        )
    }

    @Deprecated("Deprecated in Android API 33; retained for platform back navigation")
    override fun onBackPressed() {
        if (::webView.isInitialized && webView.canGoBack()) {
            webView.goBack()
        } else {
            super.onBackPressed()
        }
    }

    override fun onDestroy() {
        executor.shutdown()
        if (::webView.isInitialized) webView.destroy()
        super.onDestroy()
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == DOWNLOAD_PERMISSION_REQUEST) {
            if (grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED) {
                pendingDownloadUrl?.let { enqueueDownload(it, null, "application/vnd.android.package-archive") }
            } else {
                Toast.makeText(this, "Storage permission is needed to save the APK to Downloads.", Toast.LENGTH_LONG).show()
            }
            pendingDownloadUrl = null
        } else if (requestCode == GEOLOCATION_PERMISSION_REQUEST) {
            val granted = grantResults.any { it == PackageManager.PERMISSION_GRANTED }
            pendingGeolocationCallback?.invoke(pendingGeolocationOrigin, granted, false)
            pendingGeolocationCallback = null
            pendingGeolocationOrigin = null
        }
    }

    private fun createToolbar(): LinearLayout {
        val bar = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(12), 0, dp(8), 0)
            setBackgroundColor(0xFF0040A1.toInt())
        }
        val title = TextView(this).apply {
            text = "TrackFare"
            textSize = 16f
            setTextColor(0xFFFFFFFF.toInt())
            typeface = android.graphics.Typeface.create("sans-serif", android.graphics.Typeface.BOLD)
        }
        val details = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        details.addView(title)
        serverLabel = TextView(this).apply {
            text = Uri.parse(serverUrl).host ?: serverUrl
            textSize = 10f
            setTextColor(0xFFD9E5FF.toInt())
        }
        details.addView(serverLabel)
        bar.addView(details, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f))

        bar.addView(toolbarButton("Server") { showServerDialog() })
        nfcButton = toolbarButton("NFC") { linkPhoneNfc() }
        bar.addView(nfcButton)
        return bar
    }

    private fun loadPassengerSite() {
        webView.loadUrl(serverUrl.trimEnd('/') + "/auth/login.php")
    }

    private fun migrateServerUrl() {
        val savedUrl = preferences.getString(PhoneNfcStore.SERVER_URL, null) ?: return
        val savedHost = runCatching { Uri.parse(savedUrl).host }.getOrNull()
        if (savedHost == "192.168.1.56") {
            preferences.edit()
                .putString(PhoneNfcStore.SERVER_URL, PhoneNfcStore.DEFAULT_SERVER_URL)
                .remove(PhoneNfcStore.CREDENTIAL_ID)
                .apply()
            autoRelinkAttempted = false
        }
    }

    private fun showServerDialog() {
        val field = EditText(this).apply {
            setSingleLine(true)
            hint = "http://192.168.1.20/trackfare/"
            setText(serverUrl)
            setSelection(text.length)
            inputType = android.text.InputType.TYPE_CLASS_TEXT or
                android.text.InputType.TYPE_TEXT_VARIATION_URI
        }
        val container = LinearLayout(this).apply {
            setPadding(dp(24), dp(8), dp(24), 0)
            addView(field)
        }
        AlertDialog.Builder(this)
            .setTitle("TrackFare server")
            .setMessage("Use the XAMPP computer's local network address. No public deployment is required.")
            .setView(container)
            .setNegativeButton("Cancel", null)
            .setPositiveButton("Connect") { _, _ ->
                val updatedUrl = normalizeServerUrl(field.text.toString())
                if (updatedUrl == null) {
                    Toast.makeText(this, "Enter a valid http or https server URL.", Toast.LENGTH_LONG).show()
                } else {
                    preferences.edit().putString(PhoneNfcStore.SERVER_URL, updatedUrl).apply()
                    autoRelinkAttempted = false
                    serverLabel.text = Uri.parse(updatedUrl).host ?: updatedUrl
                    loadPassengerSite()
                }
            }
            .show()
    }

    private fun normalizeServerUrl(value: String): String? {
        val trimmed = value.trim()
        val uri = runCatching { Uri.parse(trimmed) }.getOrNull() ?: return null
        if (uri.scheme !in setOf("http", "https") || uri.host.isNullOrBlank()
            || !uri.query.isNullOrEmpty() || !uri.fragment.isNullOrEmpty()
        ) return null
        return trimmed.trimEnd('/') + "/"
    }

    private fun refreshNfcButton() {
        val adapter = NfcAdapter.getDefaultAdapter(this)
        nfcButton.text = when {
            adapter == null -> "No NFC"
            !adapter.isEnabled -> "NFC off"
            isPhoneNfcLinked() -> "Refresh NFC"
            else -> "Link NFC"
        }
        nfcButton.isEnabled = adapter != null && adapter.isEnabled
    }

    private fun isPhoneNfcLinked(): Boolean {
        if (preferences.getString(PhoneNfcStore.CREDENTIAL_ID, null).isNullOrBlank()) return false
        return hasPhoneNfcKey()
    }

    private fun hasPhoneNfcKey(): Boolean {
        val alias = preferences.getString(PhoneNfcStore.KEY_ALIAS, null) ?: return false
        return runCatching {
            KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.containsAlias(alias)
        }.getOrDefault(false)
    }

    private fun linkPhoneNfc() {
        if (NfcAdapter.getDefaultAdapter(this)?.isEnabled != true) {
            Toast.makeText(this, "Turn on NFC in Android Settings first.", Toast.LENGTH_LONG).show()
            return
        }
        relinkPhoneNfc()
    }

    private fun relinkPhoneNfc() {
        if (nfcLinkInProgress) return
        nfcLinkInProgress = true
        nfcButton.isEnabled = false
        nfcButton.text = "Linking..."
        executor.execute {
            val result = runCatching { registerPhoneCredential() }
            runOnUiThread {
                nfcLinkInProgress = false
                refreshNfcButton()
                val message = result.getOrElse { error -> error.message ?: "Phone NFC could not be linked." }
                Toast.makeText(this, message, Toast.LENGTH_LONG).show()
                if (result.isSuccess && ::webView.isInitialized) webView.reload()
            }
        }
    }

    private fun registerPhoneCredential(): String {
        val endpoint = serverUrl.trimEnd('/') + "/api/register_phone_nfc.php"
        val cookie = CookieManager.getInstance().getCookie(endpoint)
            ?: throw IllegalStateException("Sign in as a passenger first, then link phone NFC.")
        val alias = preferences.getString(PhoneNfcStore.KEY_ALIAS, null)
            ?: preferences.getString(PhoneNfcStore.PENDING_ALIAS, null)
            ?: "trackfare_phone_${UUID.randomUUID()}"
        preferences.edit().putString(PhoneNfcStore.PENDING_ALIAS, alias).apply()

        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        if (!keyStore.containsAlias(alias)) createPhoneKey(alias)
        val publicKey = keyStore.getCertificate(alias)?.publicKey?.encoded
            ?: throw IllegalStateException("Phone signing key could not be loaded.")
        val body = "public_key=" + URLEncoder.encode(
            android.util.Base64.encodeToString(publicKey, android.util.Base64.NO_WRAP),
            "UTF-8",
        )

        val connection = (URL(endpoint).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 10_000
            readTimeout = 10_000
            doOutput = true
            setRequestProperty("Cookie", cookie)
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8")
        }
        val response = try {
            connection.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            val stream = if (connection.responseCode in 200..299) connection.inputStream else connection.errorStream
            stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
        } finally {
            connection.disconnect()
        }
        val json = runCatching { JSONObject(response) }.getOrElse {
            throw IllegalStateException("TrackFare returned an invalid phone NFC response.")
        }
        if (!json.optBoolean("success")) {
            throw IllegalStateException(json.optString("message", "Phone NFC registration failed."))
        }
        val credentialId = json.optString("credential_id")
        if (!credentialId.matches(Regex("[a-fA-F0-9]{32}"))) {
            throw IllegalStateException("TrackFare returned an invalid phone credential.")
        }
        preferences.edit()
            .putString(PhoneNfcStore.CREDENTIAL_ID, credentialId.lowercase())
            .putString(PhoneNfcStore.KEY_ALIAS, alias)
            .remove(PhoneNfcStore.PENDING_ALIAS)
            .apply()
        return "Phone NFC is linked. Keep NFC enabled to tap on the bus."
    }

    private fun createPhoneKey(alias: String) {
        val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, "AndroidKeyStore")
        generator.initialize(
            KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .build(),
        )
        generator.generateKeyPair()
    }

    private fun downloadFile(url: String, contentDisposition: String?, mimeType: String?) {
        val fileName = android.webkit.URLUtil.guessFileName(url, contentDisposition, mimeType)
            .ifBlank { "TrackFare-Passenger-v1.10.apk" }
        if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.P
            && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED
        ) {
            pendingDownloadUrl = url
            requestPermissions(arrayOf(Manifest.permission.WRITE_EXTERNAL_STORAGE), DOWNLOAD_PERMISSION_REQUEST)
            return
        }
        enqueueDownload(url, fileName, mimeType)
    }

    private fun enqueueDownload(url: String, requestedName: String?, mimeType: String?) {
        val fileName = requestedName?.takeIf { it.endsWith(".apk", ignoreCase = true) }
            ?: "TrackFare-Passenger-v1.10.apk"
        runCatching {
            val request = DownloadManager.Request(Uri.parse(url))
                .setTitle(fileName)
                .setDescription("Downloading TrackFare Passenger")
                .setMimeType(mimeType ?: "application/vnd.android.package-archive")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
            CookieManager.getInstance().getCookie(url)?.let { request.addRequestHeader("Cookie", it) }
            val manager = getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
            manager.enqueue(request)
            Toast.makeText(this, "APK download started. Check Downloads when it finishes.", Toast.LENGTH_LONG).show()
        }.onFailure {
            Toast.makeText(this, it.message ?: "Could not download the APK.", Toast.LENGTH_LONG).show()
        }
    }

    private fun toolbarButton(title: String, action: () -> Unit): Button = Button(this).apply {
        text = title
        isAllCaps = false
        textSize = 11f
        minWidth = dp(48)
        setPadding(dp(4), 0, dp(4), 0)
        setTextColor(0xFFFFFFFF.toInt())
        setBackgroundColor(0x00000000)
        setOnClickListener { action() }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    companion object {
        private const val DOWNLOAD_PERMISSION_REQUEST = 44
        private const val GEOLOCATION_PERMISSION_REQUEST = 45
        private const val PAYMENT_QR_SCAN_REQUEST = 46
    }
}