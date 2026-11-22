package ca.doctorappliance.app

import android.os.Bundle
import com.getcapacitor.BridgeActivity

/**
 * The app's one screen: the live site in a WebView (capacitor.config.ts, server.url) with the card reader plugin.
 */
class MainActivity : BridgeActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        registerPlugin(SquareReaderPlugin::class.java)
        super.onCreate(savedInstanceState)
    }
}
