package ca.doctorappliance.app

import android.Manifest
import android.content.Intent
import android.os.Bundle
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import com.getcapacitor.JSObject
import com.getcapacitor.PermissionState
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.CapacitorPlugin
import com.getcapacitor.annotation.Permission
import com.getcapacitor.annotation.PermissionCallback

/**
 * Voice input for the microphone buttons (quick booking, assistant, close by voice, booking edit) with Android's own
 * speech recognition: the web view has no Web Speech API. One phrase per start(); the web side
 * (resources/js/hooks/use-dictation.ts) starts again after each phrase until the second tap, as in the browser.
 * Events: "partial" {text}, "final" {text}, "error" {code}, "end".
 */
@CapacitorPlugin(
    name = "SpeechInput",
    permissions = [Permission(alias = "microphone", strings = [Manifest.permission.RECORD_AUDIO])],
)
class SpeechInputPlugin : Plugin() {
    private var recognizer: SpeechRecognizer? = null

    @PluginMethod
    fun available(call: PluginCall) {
        call.resolve(JSObject().put("available", SpeechRecognizer.isRecognitionAvailable(context)))
    }

    @PluginMethod
    fun start(call: PluginCall) {
        if (getPermissionState("microphone") != PermissionState.GRANTED) {
            requestPermissionForAlias("microphone", call, "microphoneCallback")
            return
        }
        listen(call)
    }

    @PermissionCallback
    private fun microphoneCallback(call: PluginCall) {
        if (getPermissionState("microphone") == PermissionState.GRANTED) {
            listen(call)
        } else {
            call.reject("Microphone not allowed.", "DENIED")
        }
    }

    private fun listen(call: PluginCall) {
        if (!SpeechRecognizer.isRecognitionAvailable(context)) {
            call.reject("Speech recognition is not available on this phone.", "UNAVAILABLE")
            return
        }
        val language = call.getString("language") ?: "en-US"

        activity.runOnUiThread {
            recognizer?.destroy()
            val rec = SpeechRecognizer.createSpeechRecognizer(context)
            recognizer = rec
            rec.setRecognitionListener(object : RecognitionListener {
                override fun onReadyForSpeech(params: Bundle?) {}
                override fun onBeginningOfSpeech() {}
                override fun onRmsChanged(rmsdB: Float) {}
                override fun onBufferReceived(buffer: ByteArray?) {}
                override fun onEndOfSpeech() {}
                override fun onEvent(eventType: Int, params: Bundle?) {}

                override fun onPartialResults(partialResults: Bundle?) {
                    val text = first(partialResults) ?: return
                    notifyListeners("partial", JSObject().put("text", text))
                }

                override fun onResults(results: Bundle?) {
                    val text = first(results)
                    if (text != null) {
                        notifyListeners("final", JSObject().put("text", text))
                    }
                    finish(rec)
                }

                override fun onError(error: Int) {
                    notifyListeners("error", JSObject().put("code", errorCode(error)))
                    finish(rec)
                }
            })

            val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE, language)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE_PREFERENCE, language)
                .putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true)
                .putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1)
                .putExtra(RecognizerIntent.EXTRA_CALLING_PACKAGE, context.packageName)
                // Longer pauses before a phrase ends (people think while they talk).
                .putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_COMPLETE_SILENCE_LENGTH_MILLIS, 2500L)
                .putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_POSSIBLY_COMPLETE_SILENCE_LENGTH_MILLIS, 2000L)

            try {
                rec.startListening(intent)
                call.resolve()
            } catch (e: Exception) {
                finish(rec)
                call.reject(e.message ?: "Speech recognition failed.", "FAILED")
            }
        }
    }

    /** Ends the phrase; what was heard so far still comes as "final". */
    @PluginMethod
    fun stop(call: PluginCall) {
        activity.runOnUiThread {
            recognizer?.stopListening()
            call.resolve()
        }
    }

    /** Drops the phrase at once (leaving the screen). */
    @PluginMethod
    fun abort(call: PluginCall) {
        activity.runOnUiThread {
            recognizer?.let {
                it.cancel()
                finish(it)
            }
            call.resolve()
        }
    }

    override fun handleOnDestroy() {
        recognizer?.destroy()
        recognizer = null
        super.handleOnDestroy()
    }

    private fun finish(rec: SpeechRecognizer) {
        if (recognizer === rec) {
            recognizer = null
            rec.destroy()
            notifyListeners("end", JSObject())
        }
    }

    private fun first(bundle: Bundle?): String? =
        bundle?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull()?.takeIf { it.isNotBlank() }

    /** The Web Speech API's error names, so the web side treats both the same way. */
    private fun errorCode(error: Int): String = when (error) {
        SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> "not-allowed"
        SpeechRecognizer.ERROR_AUDIO -> "audio-capture"
        SpeechRecognizer.ERROR_NO_MATCH, SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> "no-speech"
        SpeechRecognizer.ERROR_CLIENT -> "aborted"
        SpeechRecognizer.ERROR_NETWORK, SpeechRecognizer.ERROR_NETWORK_TIMEOUT, SpeechRecognizer.ERROR_SERVER -> "network"
        SpeechRecognizer.ERROR_RECOGNIZER_BUSY -> "busy"
        else -> "failed"
    }
}
