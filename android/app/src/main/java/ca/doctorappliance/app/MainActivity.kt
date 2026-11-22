package ca.doctorappliance.app

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import com.getcapacitor.BridgeActivity

/**
 * The app's one screen: the live site in a WebView (capacitor.config.ts, server.url) with the card reader plugin.
 * A tap on a push notification opens its page here (only pages of the app's own site).
 */
class MainActivity : BridgeActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        registerPlugin(SquareReaderPlugin::class.java)
        super.onCreate(savedInstanceState)
        AppMessagingService.ensureChannel(this)
        openPushPage(intent)
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
