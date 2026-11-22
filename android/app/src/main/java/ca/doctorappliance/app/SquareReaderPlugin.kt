package ca.doctorappliance.app

import android.Manifest
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
            call.reject("The card reader is not set up in this app build.", "NOT_CONFIGURED")
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
                    is Result.Failure -> call.reject(result.errorMessage, result.errorCode.toString())
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
        val amount = call.getLong("amount")
        val referenceId = call.getString("referenceId")
        val attemptId = call.getString("paymentAttemptId") ?: referenceId
        val currency = try {
            CurrencyCode.valueOf((call.getString("currency") ?: "").uppercase())
        } catch (e: IllegalArgumentException) {
            null
        }

        if (amount == null || amount <= 0 || referenceId.isNullOrBlank() || attemptId.isNullOrBlank() || currency == null) {
            call.reject("The payment is not complete.", "USAGE_ERROR")
            return
        }

        val parameters = PaymentParameters.Builder(
            amount = Money(amount, currency),
            processingMode = ProcessingMode.ONLINE_ONLY,
            allowCardSurcharge = false,
            paymentAttemptId = attemptId,
        )
            .referenceId(referenceId)
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
                        else -> call.reject("The payment was stored offline; it is not accepted here.", "OFFLINE")
                    }
                    is Result.Failure -> call.reject(result.errorMessage, result.errorCode.toString())
                }
            }
            cancelPayment = { handle.cancel() }
        }
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
