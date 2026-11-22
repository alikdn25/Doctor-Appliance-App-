package ca.doctorappliance.app

import android.Manifest
import android.content.pm.PackageManager
import android.nfc.NfcAdapter
import android.os.Build
import com.getcapacitor.JSArray
import com.getcapacitor.JSObject
import com.getcapacitor.PermissionState
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.CapacitorPlugin
import com.getcapacitor.annotation.Permission
import com.getcapacitor.annotation.PermissionCallback
import com.squareup.sdk.mobilepayments.MobilePaymentsSdk
import com.squareup.sdk.mobilepayments.cardreader.PairingHandle
import com.squareup.sdk.mobilepayments.cardreader.ReaderInfo
import com.squareup.sdk.mobilepayments.core.CallbackReference
import com.squareup.sdk.mobilepayments.core.Result
import com.squareup.sdk.mobilepayments.mockreader.ui.MockReaderUI
import com.squareup.sdk.mobilepayments.payment.CurrencyCode
import com.squareup.sdk.mobilepayments.payment.Money
import com.squareup.sdk.mobilepayments.payment.Payment
import com.squareup.sdk.mobilepayments.payment.PaymentParameters
import com.squareup.sdk.mobilepayments.payment.ProcessingMode
import com.squareup.sdk.mobilepayments.payment.PromptMode
import com.squareup.sdk.mobilepayments.payment.PromptParameters
import org.json.JSONObject
import java.security.MessageDigest

/**
 * The card reader for the web app (resources/js/lib/card-reader.ts), around the Square Mobile Payments SDK:
 * authorize with the company's Square token and location (from the server), Bluetooth and location permissions on
 * first use, pair / list / forget readers (with state and battery), and take a payment (tap, chip, chip + PIN,
 * Interac — whatever the reader offers). Online only: every payment is checked by the server with Square before it
 * is recorded. No buyer email or phone is passed, so Square sends no receipt of its own.
 */
@CapacitorPlugin(
    name = "SquareReader",
    permissions = [
        Permission(
            alias = "bluetooth",
            strings = [Manifest.permission.BLUETOOTH_SCAN, Manifest.permission.BLUETOOTH_CONNECT],
        ),
        Permission(
            alias = "location",
            strings = [Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION],
        ),
    ],
)
class SquareReaderPlugin : Plugin() {
    private var pairingHandle: PairingHandle? = null
    private var pairingCall: PluginCall? = null
    private var readerCallback: CallbackReference? = null
    // Cancels the payment in progress (Cancel on Take payment).
    private var cancelPayment: (() -> Unit)? = null

    override fun load() {
        if (!MainApplication.squareReady) return

        try {
            readerCallback = MobilePaymentsSdk.readerManager().setReaderChangedCallback { _ ->
                notifyListeners("readerChanged", JSObject().put("readers", readersArray()))
            }
            if (MobilePaymentsSdk.authorizationManager().authorizationState.isAuthorized && MobilePaymentsSdk.isSandboxEnvironment()) {
                MockReaderUI.show()
            }
        } catch (e: Throwable) {
            // The SDK is optional for the rest of the app.
        }
    }

    override fun handleOnDestroy() {
        readerCallback?.clear()
        readerCallback = null
        super.handleOnDestroy()
    }

    private fun ready(call: PluginCall): Boolean {
        if (!MainApplication.squareReady) {
            call.reject("The card reader is not set up in this app build (no Square application ID).", "NOT_CONFIGURED", diagnostics())
            return false
        }
        return true
    }

    @PluginMethod
    fun isAuthorized(call: PluginCall) {
        if (!MainApplication.squareReady) {
            call.resolve(JSObject().put("authorized", false).put("locationId", JSONObject.NULL))
            return
        }
        val manager = MobilePaymentsSdk.authorizationManager()
        call.resolve(
            JSObject()
                .put("authorized", manager.authorizationState.isAuthorized)
                .put("locationId", manager.location?.locationId ?: JSONObject.NULL),
        )
    }

    @PluginMethod
    fun authorize(call: PluginCall) {
        if (!ready(call)) return
        val token = call.getString("accessToken")
        val location = call.getString("locationId")
        if (token.isNullOrBlank() || location.isNullOrBlank()) {
            call.reject("Square is not connected.", "NOT_CONNECTED")
            return
        }

        activity.runOnUiThread {
            MobilePaymentsSdk.authorizationManager().authorize(token, location) { result ->
                when (result) {
                    is Result.Success -> {
                        if (MobilePaymentsSdk.isSandboxEnvironment()) {
                            MockReaderUI.show()
                        }
                        call.resolve(
                            JSObject()
                                .put("authorized", true)
                                .put("location", MobilePaymentsSdk.authorizationManager().location?.name ?: JSONObject.NULL),
                        )
                    }
                    is Result.Failure -> call.reject(result.errorMessage, result.errorCode.toString(), failureData(result))
                }
            }
        }
    }

    @PluginMethod
    fun deauthorize(call: PluginCall) {
        if (!ready(call)) return
        activity.runOnUiThread {
            MobilePaymentsSdk.authorizationManager().deauthorize()
            try {
                MockReaderUI.hide()
            } catch (e: Throwable) {
                // Not shown.
            }
            call.resolve()
        }
    }

    /** Bluetooth (Android 12+) and location, asked the first time the reader is used. */
    @PluginMethod
    fun requestReaderPermissions(call: PluginCall) {
        val aliases = readerAliases()
        if (aliases.all { getPermissionState(it) == PermissionState.GRANTED }) {
            call.resolve(JSObject().put("granted", true))
            return
        }
        requestPermissionForAliases(aliases, call, "readerPermissionsCallback")
    }

    @PermissionCallback
    private fun readerPermissionsCallback(call: PluginCall) {
        call.resolve(JSObject().put("granted", readerAliases().all { getPermissionState(it) == PermissionState.GRANTED }))
    }

    private fun readerAliases(): Array<String> =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) arrayOf("bluetooth", "location") else arrayOf("location")

    @PluginMethod
    fun readers(call: PluginCall) {
        if (!ready(call)) return
        call.resolve(JSObject().put("readers", readersArray()))
    }

    @PluginMethod
    fun pair(call: PluginCall) {
        if (!ready(call)) return
        val manager = MobilePaymentsSdk.readerManager()
        if (manager.isPairingInProgress) {
            call.reject("Already looking for readers.", "BLUETOOTH_ALREADY_SCANNING")
            return
        }

        activity.runOnUiThread {
            pairingCall = call
            pairingHandle = manager.pairReader { result ->
                pairingHandle = null
                val pending = pairingCall ?: return@pairReader
                pairingCall = null
                when (result) {
                    is Result.Success -> pending.resolve(JSObject().put("paired", true))
                    is Result.Failure -> pending.reject(result.errorMessage, result.errorCode.toString())
                }
            }
        }
    }

    @PluginMethod
    fun stopPairing(call: PluginCall) {
        activity.runOnUiThread {
            pairingHandle?.stop()
            pairingHandle = null
            // The SDK may not call back after stop(): end the waiting call here.
            pairingCall?.reject("Pairing stopped.", "STOPPED")
            pairingCall = null
            call.resolve()
        }
    }

    @PluginMethod
    fun forget(call: PluginCall) {
        if (!ready(call)) return
        val reader = findReader(call) ?: return
        activity.runOnUiThread {
            MobilePaymentsSdk.readerManager().forget(reader)
            call.resolve()
        }
    }

    @PluginMethod
    fun retryConnection(call: PluginCall) {
        if (!ready(call)) return
        val reader = findReader(call) ?: return
        activity.runOnUiThread {
            MobilePaymentsSdk.readerManager().retryConnection(reader)
            call.resolve()
        }
    }

    /** Square's own reader screen (firmware, settings), as a fallback. */
    @PluginMethod
    fun showSettings(call: PluginCall) {
        if (!ready(call)) return
        activity.runOnUiThread {
            MobilePaymentsSdk.settingsManager().showSettings { result ->
                when (result) {
                    is Result.Success -> call.resolve()
                    is Result.Failure -> call.reject(result.errorMessage, result.errorCode.toString())
                }
            }
        }
    }

    @PluginMethod
    fun charge(call: PluginCall) {
        if (!ready(call)) return
        // PluginCall.getLong() returns null for any amount that fits in an Int (Android's JSONObject keeps it as an
        // Integer), so every normal payment was refused here before the SDK started. optLong reads Integer, Long,
        // Double and numeric strings.
        val amount = call.data.optLong("amount", -1L)
        val referenceId = call.getString("referenceId")
        val attemptId = call.getString("paymentAttemptId") ?: referenceId
        val currencyText = (call.getString("currency") ?: "").uppercase()
        val currency = try {
            CurrencyCode.valueOf(currencyText)
        } catch (e: IllegalArgumentException) {
            null
        }

        val invalid = listOfNotNull(
            if (amount <= 0) "amount=${call.data.opt("amount")}" else null,
            if (referenceId.isNullOrBlank()) "referenceId is empty" else null,
            if (attemptId.isNullOrBlank()) "paymentAttemptId is empty" else null,
            if (currency == null) "currency '$currencyText' is not supported by the SDK" else null,
        )
        if (invalid.isNotEmpty()) {
            call.reject(
                "Payment parameters are invalid: ${invalid.joinToString("; ")}",
                "INVALID_PARAMETERS",
                diagnostics().put("details", JSArray().apply { invalid.forEach { put(it) } }),
            )
            return
        }
        val currencyCode = currency!!
        val attempt = attemptId!!
        val reference = referenceId!!

        val parameters = PaymentParameters.Builder(
            amount = Money(amount, currencyCode),
            processingMode = ProcessingMode.ONLINE_ONLY,
            allowCardSurcharge = false,
            paymentAttemptId = attempt,
        )
            .referenceId(reference)
            .note((call.getString("note") ?: "").take(500))
            .autocomplete(true)
            .build()
        // Card only: cash and e-transfer are on the app's own screen.
        val prompt = PromptParameters(mode = PromptMode.DEFAULT, additionalPaymentMethods = emptyList())

        activity.runOnUiThread {
            val handle = MobilePaymentsSdk.paymentManager().startPaymentActivity(parameters, prompt) { result ->
                cancelPayment = null
                when (result) {
                    is Result.Success -> when (val payment = result.value) {
                        is Payment.OnlinePayment -> call.resolve(
                            JSObject()
                                .put("paymentId", payment.id)
                                .put("status", payment.status.toString()),
                        )
                        else -> call.reject("The payment was stored offline; it is not accepted here.", "OFFLINE", diagnostics())
                    }
                    is Result.Failure -> call.reject(
                        result.errorMessage,
                        result.errorCode.toString(),
                        failureData(result),
                    )
                }
            }
            cancelPayment = { handle.cancel() }
        }
    }

    /** What the phone, the app build and the SDK are: sent with every failed payment. */
    @PluginMethod
    fun diagnostics(call: PluginCall) {
        call.resolve(diagnostics())
    }

    private fun diagnostics(): JSObject {
        val data = JSObject()
            .put("sdkVersion", BuildConfig.SQUARE_SDK_VERSION)
            .put("device", "${Build.MANUFACTURER} ${Build.MODEL}")
            .put("android", "${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})")
            .put("appVersion", BuildConfig.VERSION_NAME)
            .put("appBuild", if (BuildConfig.DEBUG) "debug" else "release")
            .put("applicationId", BuildConfig.SQUARE_APPLICATION_ID)
            .put("signingSha256", signingSha256() ?: JSONObject.NULL)
            .put("nfc", nfcState())
        if (MainApplication.squareReady) {
            try {
                val auth = MobilePaymentsSdk.authorizationManager()
                data.put("environment", if (MobilePaymentsSdk.isSandboxEnvironment()) "sandbox" else "production")
                    .put("authorized", auth.authorizationState.isAuthorized)
                    .put("sdkLocationId", auth.location?.locationId ?: JSONObject.NULL)
                    .put("sdkLocationName", auth.location?.name ?: JSONObject.NULL)
            } catch (e: Throwable) {
                data.put("sdkState", e.toString())
            }
        } else {
            data.put("environment", "not initialized")
        }
        return data
    }

    /**
     * Everything the SDK says about a failure: errorCode and errorMessage, plus debugCode, debugMessage and details
     * (read by name so the build does not depend on their exact types).
     */
    private fun failureData(failure: Any): JSObject {
        val data = diagnostics()
        fun field(name: String): Any? = try {
            failure.javaClass.methods
                .firstOrNull { it.name == "get" + name.replaceFirstChar { c -> c.uppercase() } && it.parameterCount == 0 }
                ?.invoke(failure)
        } catch (e: Throwable) {
            null
        }
        data.put("debugCode", field("debugCode")?.toString() ?: JSONObject.NULL)
        data.put("debugMessage", field("debugMessage")?.toString() ?: JSONObject.NULL)
        val details = JSArray()
        when (val raw = field("details")) {
            is Iterable<*> -> raw.forEach { details.put(it.toString()) }
            null -> Unit
            else -> details.put(raw.toString())
        }
        data.put("details", details)
        data.put("raw", failure.toString().take(2000))
        return data
    }

    private fun signingSha256(): String? = try {
        val info = context.packageManager.getPackageInfo(context.packageName, PackageManager.GET_SIGNING_CERTIFICATES)
        val signers = info.signingInfo?.let {
            if (it.hasMultipleSigners()) it.apkContentsSigners else it.signingCertificateHistory
        }
        signers?.lastOrNull()?.toByteArray()?.let { bytes ->
            MessageDigest.getInstance("SHA-256").digest(bytes).joinToString(":") { "%02X".format(it) }
        }
    } catch (e: Throwable) {
        null
    }

    private fun nfcState(): String = try {
        val adapter = NfcAdapter.getDefaultAdapter(context)
        when {
            adapter == null -> "none"
            adapter.isEnabled -> "on"
            else -> "off"
        }
    } catch (e: Throwable) {
        "unknown"
    }

    @PluginMethod
    fun cancelPayment(call: PluginCall) {
        activity.runOnUiThread {
            val cancel = cancelPayment
            cancelPayment = null
            cancel?.invoke()
            call.resolve(JSObject().put("cancelled", cancel != null))
        }
    }

    private fun findReader(call: PluginCall): ReaderInfo? {
        val id = call.getString("id")
        val reader = MobilePaymentsSdk.readerManager().getReaders().firstOrNull { it.id.toString() == id }
        if (reader == null) {
            call.reject("Reader not found.", "READER_NOT_FOUND")
        }
        return reader
    }

    private fun readersArray(): JSArray {
        val list = JSArray()
        if (!MainApplication.squareReady) return list

        for (reader in MobilePaymentsSdk.readerManager().getReaders()) {
            val status = when (reader.status) {
                is ReaderInfo.Status.Ready -> "ready"
                is ReaderInfo.Status.ConnectingToDevice, is ReaderInfo.Status.ConnectingToSquare -> "connecting"
                is ReaderInfo.Status.Faulty -> "faulty"
                else -> "unavailable"
            }
            list.put(
                JSObject()
                    .put("id", reader.id.toString())
                    .put("name", reader.name ?: JSONObject.NULL)
                    .put("model", reader.model.toString())
                    .put("serial", reader.serialNumber ?: JSONObject.NULL)
                    .put("status", status)
                    .put("battery", reader.batteryStatus?.percent ?: JSONObject.NULL)
                    .put("charging", reader.batteryStatus?.isCharging ?: false),
            )
        }
        return list
    }
}
