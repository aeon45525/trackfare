package com.trackfare.phone

import android.Manifest
import androidx.activity.result.contract.ActivityResultContracts
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.PorterDuff
import android.graphics.PorterDuffXfermode
import android.graphics.RectF
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.ComponentActivity
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import com.google.mlkit.vision.barcode.BarcodeScanner
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

class PaymentQrScannerActivity : ComponentActivity() {
    private lateinit var previewView: PreviewView
    private lateinit var statusText: TextView
    private lateinit var flashButton: Button
    private lateinit var cameraExecutor: ExecutorService
    private lateinit var barcodeScanner: BarcodeScanner
    private var camera: Camera? = null
    private var awaitingCameraSettings = false
    private val resultSent = AtomicBoolean(false)
    private val unsupportedShown = AtomicBoolean(false)
    private val cameraPermissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) startCamera()
        else showPermissionMessage(shouldShowRequestPermissionRationale(Manifest.permission.CAMERA))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = Color.BLACK
        window.navigationBarColor = Color.BLACK
        cameraExecutor = Executors.newSingleThreadExecutor()
        barcodeScanner = BarcodeScanning.getClient(
            BarcodeScannerOptions.Builder()
                .setBarcodeFormats(Barcode.FORMAT_QR_CODE)
                .build(),
        )

        if (!packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY)) {
            showMessage("No camera is available on this device.", closeOnly = true)
            return
        }

        setContentView(createScannerView())
        if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
            startCamera()
        } else if (shouldShowRequestPermissionRationale(Manifest.permission.CAMERA)) {
            showPermissionMessage(canRetry = true)
        } else if (getPreferences(MODE_PRIVATE).getBoolean(PREF_ASKED_CAMERA, false)) {
            showPermissionMessage(canRetry = false)
        } else {
            requestCameraPermission()
        }
    }

    override fun onDestroy() {
        camera?.cameraControl?.enableTorch(false)
        if (::barcodeScanner.isInitialized) barcodeScanner.close()
        if (::cameraExecutor.isInitialized) cameraExecutor.shutdownNow()
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
        if (!awaitingCameraSettings) return
        awaitingCameraSettings = false
        if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
            startCamera()
        } else {
            showPermissionMessage(canRetry = false)
        }
    }

    private fun createScannerView(): View {
        val root = FrameLayout(this).apply { setBackgroundColor(Color.BLACK) }
        previewView = PreviewView(this).apply {
            implementationMode = PreviewView.ImplementationMode.COMPATIBLE
            scaleType = PreviewView.ScaleType.FILL_CENTER
        }
        root.addView(previewView, FrameLayout.LayoutParams(-1, -1))
        root.addView(ScanFrameView(this), FrameLayout.LayoutParams(-1, -1))

        val topBar = LinearLayout(this).apply {
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(12), dp(8), dp(12), dp(8))
            setBackgroundColor(0x99000000.toInt())
        }
        topBar.addView(actionButton("Cancel") { finish() }, LinearLayout.LayoutParams(0, dp(48), 1f))
        flashButton = actionButton("Flash off") { toggleFlash() }
        flashButton.isEnabled = false
        topBar.addView(flashButton, LinearLayout.LayoutParams(0, dp(48), 1f))
        root.addView(topBar, FrameLayout.LayoutParams(-1, dp(64), Gravity.TOP))

        val bottomPanel = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(dp(20), dp(14), dp(20), dp(20))
            setBackgroundColor(0xCC000000.toInt())
        }
        statusText = TextView(this).apply {
            text = "Align a TrackFare QR inside the frame. Use the flashlight in low light."
            textSize = 15f
            gravity = Gravity.CENTER
            setTextColor(Color.WHITE)
        }
        bottomPanel.addView(statusText, LinearLayout.LayoutParams(-1, ViewGroup.LayoutParams.WRAP_CONTENT))
        root.addView(bottomPanel, FrameLayout.LayoutParams(-1, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.BOTTOM))
        return root
    }

    private fun startCamera() {
        if (!::previewView.isInitialized) return
        statusText.text = "Align a TrackFare QR inside the frame. Use the flashlight in low light."
        val providerFuture = ProcessCameraProvider.getInstance(this)
        providerFuture.addListener({
            try {
                val provider = providerFuture.get()
                val preview = Preview.Builder().build().also {
                    it.surfaceProvider = previewView.surfaceProvider
                }
                val analysis = ImageAnalysis.Builder()
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                    .build()
                analysis.setAnalyzer(cameraExecutor, ::analyzeImage)
                provider.unbindAll()
                camera = provider.bindToLifecycle(
                    this,
                    CameraSelector.DEFAULT_BACK_CAMERA,
                    preview,
                    analysis,
                )
                flashButton.isEnabled = camera?.cameraInfo?.hasFlashUnit() == true
                flashButton.text = if (flashButton.isEnabled) "Flash off" else "No flash"
            } catch (_: Exception) {
                showMessage("The camera could not start. Close other camera apps and try again.", closeOnly = true)
            }
        }, mainExecutor)
    }

    private fun analyzeImage(imageProxy: ImageProxy) {
        val mediaImage = imageProxy.image
        if (mediaImage == null || resultSent.get()) {
            imageProxy.close()
            return
        }
        val image = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
        barcodeScanner.process(image)
            .addOnSuccessListener { barcodes ->
                val payload = barcodes.firstNotNullOfOrNull { barcode ->
                    barcode.rawValue?.takeIf {
                        TrackFarePaymentQr.parseRequestId(it) != null
                            || TrackFarePaymentQr.parseBusTap(it) != null
                    }
                }
                if (payload != null && resultSent.compareAndSet(false, true)) {
                    setResult(RESULT_OK, Intent().putExtra(EXTRA_PAYLOAD, payload))
                    finish()
                } else if (barcodes.isNotEmpty() && unsupportedShown.compareAndSet(false, true)) {
                    runOnUiThread { statusText.text = "Unsupported QR. Scan a TrackFare code." }
                }
            }
            .addOnCompleteListener { imageProxy.close() }
    }

    private fun toggleFlash() {
        val selectedCamera = camera ?: return
        val enable = flashButton.text.toString() == "Flash off"
        selectedCamera.cameraControl.enableTorch(enable)
        flashButton.text = if (enable) "Flash on" else "Flash off"
    }

    private fun requestCameraPermission() {
        getPreferences(MODE_PRIVATE).edit().putBoolean(PREF_ASKED_CAMERA, true).apply()
        cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
    }

    private fun showPermissionMessage(canRetry: Boolean) {
        showMessage(
            "Camera access is needed to scan a TrackFare QR. " +
                if (canRetry) "Allow access to continue." else "Enable camera access in Android Settings.",
            primaryText = "Open settings",
            primaryAction = { openAppSettings() },
            secondaryText = if (canRetry) "Try again" else null,
            secondaryAction = if (canRetry) ({ requestCameraPermission() }) else null,
        )
    }

    private fun showMessage(
        message: String,
        closeOnly: Boolean = false,
        primaryText: String = "Open settings",
        primaryAction: (() -> Unit)? = null,
        secondaryText: String? = null,
        secondaryAction: (() -> Unit)? = null,
    ) {
        val container = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(dp(28), dp(28), dp(28), dp(28))
            setBackgroundColor(Color.BLACK)
        }
        val label = TextView(this).apply {
            text = message
            textSize = 17f
            gravity = Gravity.CENTER
            setTextColor(Color.WHITE)
        }
        container.addView(label, LinearLayout.LayoutParams(-1, 0, 1f))
        if (!closeOnly && primaryAction != null) {
            container.addView(actionButton(primaryText, primaryAction), LinearLayout.LayoutParams(-1, dp(52)))
        }
        if (!closeOnly && secondaryText != null && secondaryAction != null) {
            container.addView(actionButton(secondaryText, secondaryAction), LinearLayout.LayoutParams(-1, dp(52)))
        }
        container.addView(actionButton(if (closeOnly) "Close" else "Cancel") { finish() },
            LinearLayout.LayoutParams(-1, dp(52)))
        setContentView(container)
    }

    private fun openAppSettings() {
        awaitingCameraSettings = true
        startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.fromParts("package", packageName, null)
        })
    }

    private fun actionButton(label: String, action: () -> Unit): Button = Button(this).apply {
        text = label
        isAllCaps = false
        setTextColor(Color.WHITE)
        setBackgroundColor(0xFF0040A1.toInt())
        setOnClickListener { action() }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    private class ScanFrameView(context: android.content.Context) : View(context) {
        private val scrim = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0x77000000 }
        private val clear = Paint(Paint.ANTI_ALIAS_FLAG).apply { xfermode = PorterDuffXfermode(PorterDuff.Mode.CLEAR) }
        private val border = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.WHITE
            style = Paint.Style.STROKE
            strokeWidth = 3f * resources.displayMetrics.density
        }

        init {
            setLayerType(LAYER_TYPE_HARDWARE, null)
        }

        override fun onDraw(canvas: Canvas) {
            super.onDraw(canvas)
            val side = 264f * resources.displayMetrics.density
            val left = (width - side) / 2
            val top = (height - side) / 2
            val frame = RectF(left, top, left + side, top + side)
            canvas.drawRect(0f, 0f, width.toFloat(), height.toFloat(), scrim)
            canvas.drawRoundRect(frame, 12f, 12f, clear)
            canvas.drawRoundRect(frame, 12f, 12f, border)
        }
    }

    companion object {
        const val EXTRA_PAYLOAD = "payment_qr_payload"
        private const val PREF_ASKED_CAMERA = "camera_permission_asked"
    }
}