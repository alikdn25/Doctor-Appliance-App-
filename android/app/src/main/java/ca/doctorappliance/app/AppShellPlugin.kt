package ca.doctorappliance.app

import android.content.Intent
import android.net.Uri
import android.os.Build
import com.getcapacitor.JSObject
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.CapacitorPlugin

/**
 * Small things the web app needs from the Android shell (resources/js/lib/native-shell.ts): the installed version
 * (for "Update available") and opening a link outside the app (the APK download goes to the phone's browser).
 */
@CapacitorPlugin(name = "AppShell")
class AppShellPlugin : Plugin() {
    @PluginMethod
    fun info(call: PluginCall) {
        val info = context.packageManager.getPackageInfo(context.packageName, 0)
        val code = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) info.longVersionCode else {
            @Suppress("DEPRECATION")
            info.versionCode.toLong()
        }
        call.resolve(
            JSObject()
                .put("versionCode", code)
                .put("versionName", info.versionName ?: ""),
        )
    }

    @PluginMethod
    fun openExternal(call: PluginCall) {
        val url = call.getString("url")
        val uri = url?.let { Uri.parse(it) }
        if (uri == null || (uri.scheme != "https" && uri.scheme != "http")) {
            call.reject("Not a web address.", "BAD_URL")
            return
        }
        try {
            activity.startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            call.resolve()
        } catch (e: Exception) {
            call.reject("No browser on this phone.", "NO_BROWSER")
        }
    }
}
