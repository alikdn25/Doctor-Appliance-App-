package ca.doctorappliance.app

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.webkit.CookieManager
import androidx.core.app.NotificationManagerCompat
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

/**
 * A button on a push notification that runs on the server (move the visit, Book, Later …): the same request the
 * browser's service worker sends (POST push-actions/{token}/{action}, signed in with the app's session cookie), then a
 * short notification with the result.
 */
class PushActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val actionUrl = intent.getStringExtra(EXTRA_ACTION_URL) ?: return
        val key = intent.getStringExtra(EXTRA_KEY) ?: return
        val notificationId = intent.getIntExtra(EXTRA_NOTIFICATION_ID, 0)
        // The notification whose button was tapped goes away; the result comes as a new one.
        NotificationManagerCompat.from(context).cancel(notificationId)
        val pending = goAsync()

        Thread {
            val message = try {
                run(actionUrl.replace("__action__", URLEncoder.encode(key, "UTF-8")))
            } catch (e: Exception) {
                context.getString(R.string.push_action_offline)
            }
            AppMessagingService.show(context, message, "", null, "push-action-result:$notificationId", null, null)
            pending.finish()
        }.start()
    }

    private fun run(url: String): String {
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.requestMethod = "POST"
        connection.connectTimeout = 15000
        connection.readTimeout = 15000
        connection.setRequestProperty("Accept", "application/json")
        connection.setRequestProperty("X-Requested-With", "XMLHttpRequest")
        CookieManager.getInstance().getCookie(url)?.let { connection.setRequestProperty("Cookie", it) }
        connection.doOutput = true
        connection.outputStream.use { it.write(ByteArray(0)) }

        val ok = connection.responseCode in 200..299
        val text = (if (ok) connection.inputStream else connection.errorStream)?.bufferedReader()?.use { it.readText() } ?: ""
        connection.disconnect()

        val message = try {
            JSONObject(text).optString("message")
        } catch (e: Exception) {
            ""
        }
        return message.ifBlank { if (ok) "Done" else "Could not do it. Open the app and try again." }
    }

    companion object {
        private const val EXTRA_ACTION_URL = "action_url"
        private const val EXTRA_KEY = "action_key"
        private const val EXTRA_NOTIFICATION_ID = "notification_id"

        fun intent(context: Context, actionUrl: String, key: String, notificationId: Int, requestCode: Int): PendingIntent {
            val intent = Intent(context, PushActionReceiver::class.java).apply {
                putExtra(EXTRA_ACTION_URL, actionUrl)
                putExtra(EXTRA_KEY, key)
                putExtra(EXTRA_NOTIFICATION_ID, notificationId)
            }
            return PendingIntent.getBroadcast(context, requestCode, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        }
    }
}
