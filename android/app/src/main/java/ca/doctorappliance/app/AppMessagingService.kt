package ca.doctorappliance.app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.capacitorjs.plugins.pushnotifications.MessagingService
import com.google.firebase.messaging.RemoteMessage
import org.json.JSONArray

/**
 * Shows the app's push notifications (owner's task of November 22). The server sends Firebase data messages with the
 * same content as browser push: title, body, the page to open, and up to two buttons. A tap opens the page in the app
 * (MainActivity); a button either opens its page or runs on the server (PushActionReceiver), as in the browser.
 * The Capacitor plugin still gets the message (super) and the token updates.
 */
class AppMessagingService : MessagingService() {
    override fun onMessageReceived(remoteMessage: RemoteMessage) {
        super.onMessageReceived(remoteMessage)

        val data = remoteMessage.data
        val title = data["title"] ?: remoteMessage.notification?.title ?: return
        show(this, title, data["body"] ?: remoteMessage.notification?.body ?: "", data["url"], data["tag"], data["actions"], data["action_url"])
    }

    companion object {
        const val CHANNEL_ID = "alerts"
        const val EXTRA_URL = "push_url"

        fun ensureChannel(context: Context) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                val manager = context.getSystemService(NotificationManager::class.java)
                if (manager.getNotificationChannel(CHANNEL_ID) == null) {
                    manager.createNotificationChannel(
                        NotificationChannel(CHANNEL_ID, context.getString(R.string.push_channel_name), NotificationManager.IMPORTANCE_HIGH),
                    )
                }
            }
        }

        fun openIntent(context: Context, url: String?, requestCode: Int): PendingIntent {
            val intent = Intent(context, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
                putExtra(EXTRA_URL, url)
            }
            return PendingIntent.getActivity(context, requestCode, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        }

        fun show(context: Context, title: String, body: String, url: String?, tag: String?, actionsJson: String?, actionUrl: String?) {
            ensureChannel(context)
            val id = (tag ?: (title + body)).hashCode()
            val builder = NotificationCompat.Builder(context, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_stat_notify)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(NotificationCompat.BigTextStyle().bigText(body))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(openIntent(context, url, id))

            val actions = try {
                if (actionsJson.isNullOrBlank()) JSONArray() else JSONArray(actionsJson)
            } catch (e: Exception) {
                JSONArray()
            }

            for (i in 0 until minOf(actions.length(), 3)) {
                val action = actions.optJSONObject(i) ?: continue
                val key = action.optString("action")
                val label = action.optString("title")
                val pageUrl = action.optString("url").ifBlank { null }
                val requestCode = id + i + 1
                val pending = when {
                    pageUrl != null -> openIntent(context, pageUrl, requestCode)
                    !actionUrl.isNullOrBlank() && key.isNotBlank() -> PushActionReceiver.intent(context, actionUrl, key, id, requestCode)
                    else -> null
                } ?: continue
                builder.addAction(0, label, pending)
            }

            try {
                NotificationManagerCompat.from(context).notify(id, builder.build())
            } catch (e: SecurityException) {
                // Notifications are off for the app.
            }
        }
    }
}
