package ca.doctorappliance.app

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.OnBackPressedCallback
import com.getcapacitor.BridgeActivity

/**
 * The app's one screen: the live site in a WebView (capacitor.config.ts, server.url) with the card reader, voice input
 * and shell plugins. A tap on a push notification opens its page here (only pages of the app's own site). The back
 * button does what the ← arrow on the screen does (resources/js/lib/native-shell.ts); on a screen without one the app
 * goes to the background.
 */
class MainActivity : BridgeActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        registerPlugin(SquareReaderPlugin::class.java)
        registerPlugin(SpeechInputPlugin::class.java)
        registerPlugin(AppShellPlugin::class.java)
        super.onCreate(savedInstanceState)
        AppMessagingService.ensureChannel(this)
        openPushPage(intent)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                val webView = bridge?.webView
                if (webView == null) {
                    moveTaskToBack(true)
                    return
                }
                webView.evaluateJavascript("(function(){try{return !!(window.__daBack && window.__daBack());}catch(e){return false;}})()") { handled ->
                    if (handled != "true") {
                        // The offline page or a page that is not the app's: browser history, else the background.
                        val onSite = (webView.url ?: "").startsWith(bridge.config.serverUrl ?: "")
                        if (!onSite && webView.canGoBack()) {
                            webView.goBack()
                        } else {
                            moveTaskToBack(true)
                        }
                    }
                }
            }
        })
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        openPushPage(intent)
    }

    private fun openPushPage(intent: Intent?) {
        val url = intent?.getStringExtra(AppMessagingService.EXTRA_URL) ?: return
        intent.removeExtra(AppMessagingService.EXTRA_URL)
        val server = bridge?.config?.serverUrl ?: return

        if (Uri.parse(url).host != Uri.parse(server).host) {
            return
        }

        bridge.webView.post { bridge.webView.loadUrl(url) }
    }
}
