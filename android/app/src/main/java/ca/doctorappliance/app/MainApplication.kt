package ca.doctorappliance.app

import android.app.Application
import android.util.Log
import com.squareup.sdk.mobilepayments.MobilePaymentsSdk

/**
 * Starts the Square Mobile Payments SDK with the Square application ID the APK was built with (sandbox or
 * production). Without one the app still works; only the card reader is unavailable.
 */
class MainApplication : Application() {
    override fun onCreate() {
        super.onCreate()

        if (BuildConfig.SQUARE_APPLICATION_ID.isNotBlank()) {
            try {
                MobilePaymentsSdk.initialize(BuildConfig.SQUARE_APPLICATION_ID, this)
                squareReady = true
            } catch (e: Throwable) {
                Log.e("SquareReader", "Mobile Payments SDK did not start", e)
            }
        }
    }

    companion object {
        @Volatile
        var squareReady: Boolean = false
            private set
    }
}
